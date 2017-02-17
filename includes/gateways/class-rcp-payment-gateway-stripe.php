<?php
/**
 * Stripe Payment Gateway
 *
 * @package     Restrict Content Pro
 * @subpackage  Classes/Gateways/Stripe
 * @copyright   Copyright (c) 2017, Pippin Williamson
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       2.1
*/

class RCP_Payment_Gateway_Stripe extends RCP_Payment_Gateway {

	protected $secret_key;
	protected $publishable_key;

	/**
	 * Get things going
	 *
	 * @access public
	 * @since  2.1
	 * @return void
	 */
	public function init() {

		global $rcp_options;

		$this->supports[] = 'one-time';
		$this->supports[] = 'recurring';
		$this->supports[] = 'fees';
		$this->supports[] = 'gateway-submits-form';
		$this->supports[] = 'trial';

		if( $this->test_mode ) {

			$this->secret_key      = isset( $rcp_options['stripe_test_secret'] )      ? trim( $rcp_options['stripe_test_secret'] )      : '';
			$this->publishable_key = isset( $rcp_options['stripe_test_publishable'] ) ? trim( $rcp_options['stripe_test_publishable'] ) : '';

		} else {

			$this->secret_key      = isset( $rcp_options['stripe_live_secret'] )      ? trim( $rcp_options['stripe_live_secret'] )      : '';
			$this->publishable_key = isset( $rcp_options['stripe_live_publishable'] ) ? trim( $rcp_options['stripe_live_publishable'] ) : '';

		}

		if( ! class_exists( 'Stripe\Stripe' ) ) {
			require_once RCP_PLUGIN_DIR . 'includes/libraries/stripe/init.php';
		}

	}

	/**
	 * Process registration
	 *
	 * @access public
	 * @since  2.1
	 * @return void
	 */
	public function process_signup() {

		\Stripe\Stripe::setApiKey( $this->secret_key );

		if ( method_exists( '\Stripe\Stripe', 'setAppInfo' ) ) {
			\Stripe\Stripe::setAppInfo( 'Restrict Content Pro', RCP_PLUGIN_VERSION, esc_url( site_url() ) );
		}

		$paid   = false;
		$member = new RCP_Member( $this->user_id );
		$customer_exists = false;

		if( empty( $_POST['stripeToken'] ) ) {
			wp_die( __( 'Missing Stripe token, please try again or contact support if the issue persists.', 'rcp' ), __( 'Error', 'rcp' ), array( 'response' => 400 ) );
		}

		$customer_id = $member->get_payment_profile_id();

		if ( $customer_id ) {

			$customer_exists = true;

			try {

				// Update the customer to ensure their card data is up to date
				$customer = \Stripe\Customer::retrieve( $customer_id );

				if( isset( $customer->deleted ) && $customer->deleted ) {

					// This customer was deleted
					$customer_exists = false;

				}

			// No customer found
			} catch ( Exception $e ) {

				$customer_exists = false;

			}

		}

		if( empty( $customer_exists ) ) {

			try {

				$customer_args = array(
					'card'  => $_POST['stripeToken'],
					'email' => $this->email
				);

				$customer = \Stripe\Customer::create( apply_filters( 'rcp_stripe_customer_create_args', $customer_args, $this ) );

				// A temporary invoice is created to force the customer's currency to be set to the store currency. See https://github.com/restrictcontentpro/restrict-content-pro/issues/549
				if ( ! empty( $this->signup_fee ) ) {

					\Stripe\InvoiceItem::create( array(
						'customer'    => $customer->id,
						'amount'      => 0,
						'currency'    => rcp_get_currency(),
						'description' => 'Setting Customer Currency',
					) );

					$temp_invoice = \Stripe\Invoice::create( array(
						'customer' => $customer->id,
					) );

				}

				$member->set_payment_profile_id( $customer->id );

			} catch ( Exception $e ) {

				$this->handle_processing_error( $e );

			}

		} else {

			$customer->source = $_POST['stripeToken'];

		}

		$customer->description = 'User ID: ' . $this->user_id . ' - User Email: ' . $this->email . ' Subscription: ' . $this->subscription_name;
		$customer->metadata    = array(
			'user_id'      => $this->user_id,
			'email'        => $this->email,
			'subscription' => $this->subscription_name
		);

		try {
			$customer->save();
		} catch( Exception $e ) {
			$this->handle_processing_error( $e );
		}

		if ( $this->auto_renew ) {

			// process a subscription sign up
			if ( ! $plan_id = $this->plan_exists( $this->subscription_name ) ) {
				// create the plan if it doesn't exist
				$plan_id = $this->create_plan( $this->subscription_name );
			}

			try {

				// Add fees before the plan is updated and charged
				if ( ! empty( $this->signup_fee ) ) {

					$customer->account_balance = $customer->account_balance + ( $this->signup_fee * rcp_stripe_get_currency_multiplier() ); // Add additional amount to initial payment (in cents)
					$customer->save();

					if( isset( $temp_invoice ) ) {
						$invoice = \Stripe\Invoice::retrieve( $temp_invoice->id );
						$invoice->closed = true;
						$invoice->save();
						unset( $temp_invoice, $invoice );
					}
				}

				// clean up any past due or unpaid subscriptions before upgrading/downgrading
				foreach( $customer->subscriptions->all()->data as $subscription ) {

					// check if we are renewing an existing subscription. This should not ever be 'active', if it is Stripe
					// will do nothing. If it is 'past_due' the most recent invoice will be paid and the subscription will become active
					if ( $subscription->plan->id == $plan_id && in_array( $subscription->status, array( 'active', 'past_due' ) ) ) {
						continue;
					}

					// remove any subscriptions that are past_due or inactive
					if ( in_array( $subscription->status, array( 'past_due', 'unpaid' ) ) ) {
						$subscription->cancel();
					}
				}

				// If the customer has an existing subscription, we need to cancel it
				if( $member->just_upgraded() && $member->can_cancel() ) {
					$cancelled = $member->cancel_payment_profile( false );
				}

				$sub_args = array(
					'plan'    => $plan_id,
					'prorate' => false
				);

				if ( ! empty( $this->discount_code ) ) {

					$sub_args['coupon'] = $this->discount_code;

				}

				// Is this a free trial?
				if ( $this->is_trial() ) {
					$sub_args['trial_end'] = strtotime( $this->subscription_data['trial_duration'] . ' ' . $this->subscription_data['trial_duration_unit'], current_time( 'timestamp' ) );
				}

				// Set the customer's subscription in Stripe
				$subscription = $customer->subscriptions->create( array( $sub_args ) );

				$member->set_merchant_subscription_id( $subscription->id );

				// subscription payments are recorded via webhook

				$paid = true;

			} catch ( \Stripe\Error\Card $e ) {

				$this->handle_processing_error( $e );

			} catch ( \Stripe\Error\InvalidRequest $e ) {

				// Invalid parameters were supplied to Stripe's API
				$this->handle_processing_error( $e );

			} catch ( \Stripe\Error\Authentication $e ) {

				// Authentication with Stripe's API failed
				// (maybe you changed API keys recently)
				$this->handle_processing_error( $e );

			} catch ( \Stripe\Error\ApiConnection $e ) {

				// Network communication with Stripe failed
				$this->handle_processing_error( $e );

			} catch ( \Stripe\Error\Base $e ) {

				// Display a very generic error to the user
				$this->handle_processing_error( $e );

			} catch ( Exception $e ) {

				// Something else happened, completely unrelated to Stripe

				$error = '<p>' . __( 'An unidentified error occurred.', 'rcp' ) . '</p>';
				$error .= print_r( $e, true );

				wp_die( $error, __( 'Error', 'rcp' ), array( 'response' => 401 ) );

			}

		} else {

			// process a one time payment signup

			try {

				$charge = \Stripe\Charge::create( apply_filters( 'rcp_stripe_charge_create_args', array(
					'amount'         => round( ( $this->amount + $this->signup_fee ) * rcp_stripe_get_currency_multiplier(), 0 ), // amount in cents
					'currency'       => strtolower( $this->currency ),
					'customer'       => $customer->id,
					'description'    => 'User ID: ' . $this->user_id . ' - User Email: ' . $this->email . ' Subscription: ' . $this->subscription_name,
					'receipt_email'  => $this->email,
					'metadata'       => array(
						'email'      => $this->email,
						'user_id'    => $this->user_id,
						'level_id'   => $this->subscription_id,
						'level'      => $this->subscription_name,
						'key'        => $this->subscription_key
					)
				), $this ) );

				$payment_data = array(
					'date'              => date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ),
					'subscription'      => $this->subscription_name,
					'payment_type' 		=> 'Credit Card One Time',
					'subscription_key' 	=> $this->subscription_key,
					'amount' 			=> $this->amount + $this->signup_fee,
					'user_id' 			=> $this->user_id,
					'transaction_id'    => $charge->id
				);

				$rcp_payments = new RCP_Payments();
				$rcp_payments->insert( $payment_data );

				$paid = true;

			} catch ( \Stripe\Error\Card $e ) {

				$this->handle_processing_error( $e );

			} catch ( \Stripe\Error\InvalidRequest $e ) {

				// Invalid parameters were supplied to Stripe's API
				$this->handle_processing_error( $e );


			} catch ( \Stripe\Error\Authentication $e ) {

				// Authentication with Stripe's API failed
				// (maybe you changed API keys recently)
				$this->handle_processing_error( $e );

			} catch ( \Stripe\Error\ApiConnection $e ) {

				// Network communication with Stripe failed
				$this->handle_processing_error( $e );


			} catch ( \Stripe\Error\Base $e ) {

				// Display a very generic error to the user
				$this->handle_processing_error( $e );

			} catch ( Exception $e ) {

				// Something else happened, completely unrelated to Stripe

				$error = '<p>' . __( 'An unidentified error occurred.', 'rcp' ) . '</p>';
				$error .= print_r( $e, true );

				wp_die( $error, __( 'Error', 'rcp' ), array( 'response' => 401 ) );

			}
		}

		if ( $paid ) {

			// If this is a one-time signup and the customer has an existing subscription, we need to cancel it
			if( ! $this->auto_renew && $member->just_upgraded() && $member->can_cancel() ) {
				$cancelled = $member->cancel_payment_profile( false );
			}

			$member->set_recurring( $this->auto_renew );

			if ( ! is_user_logged_in() ) {

				// log the new user in
				rcp_login_user_in( $this->user_id, $this->user_name, $_POST['rcp_user_pass'] );

			}

			if( ! $this->auto_renew ) {
				$member->set_expiration_date( $member->calculate_expiration() );
			}

			// set this user to active
			$member->set_status( 'active' );

			do_action( 'rcp_stripe_signup', $this->user_id, $this );

		} else {

			wp_die( __( 'An error occurred, please contact the site administrator: ', 'rcp' ) . get_bloginfo( 'admin_email' ), __( 'Error', 'rcp' ), array( 'response' => 401 ) );

		}

		// redirect to the success page, or error page if something went wrong
		wp_redirect( $this->return_url ); exit;

	}

	/**
	 * Handle Stripe processing error
	 *
	 * @param $e
	 *
	 * @access protected
	 * @since  2.5
	 * @return void
	 */
	protected function handle_processing_error( $e ) {
		$body = $e->getJsonBody();
		$err  = $body['error'];

		do_action( 'rcp_stripe_signup_payment_failed', $err, $this );

		$error = '<h4>' . __( 'An error occurred', 'rcp' ) . '</h4>';
		if( isset( $err['code'] ) ) {
			$error .= '<p>' . sprintf( __( 'Error code: %s', 'rcp' ), $err['code'] ) . '</p>';
		}
		$error .= "<p>Status: " . $e->getHttpStatus() ."</p>";
		$error .= "<p>Message: " . $err['message'] . "</p>";

		wp_die( $error, __( 'Error', 'rcp' ), array( 'response' => 401 ) );
	}

	/**
	 * Process webhooks
	 *
	 * @access public
	 * @return void
	 */
	public function process_webhooks() {

		if( ! isset( $_GET['listener'] ) || strtolower( $_GET['listener'] ) != 'stripe' ) {
			return;
		}

		// Ensure listener URL is not cached by W3TC
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		\Stripe\Stripe::setApiKey( $this->secret_key );

		// retrieve the request's body and parse it as JSON
		$body          = @file_get_contents( 'php://input' );
		$event_json_id = json_decode( $body );
		$expiration    = '';

		// for extra security, retrieve from the Stripe API
		if ( isset( $event_json_id->id ) ) {

			$rcp_payments = new RCP_Payments();

			$event_id = $event_json_id->id;

			try {

				$event         = \Stripe\Event::retrieve( $event_id );
				$payment_event = $event->data->object;

				if( empty( $payment_event->customer ) ) {
					die( 'no customer attached' );
				}

				// retrieve the customer who made this payment (only for subscriptions)
				$user = rcp_get_member_id_from_profile_id( $payment_event->customer );

				if( empty( $user ) ) {

					// Grab the customer ID from the old meta keys
					global $wpdb;
					$user = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM $wpdb->usermeta WHERE meta_key = '_rcp_stripe_user_id' AND meta_value = %s LIMIT 1", $payment_event->customer ) );

				}

				if( empty( $user ) ) {
					die( 'no user ID found' );
				}

				$member = new RCP_Member( $user );

				// check to confirm this is a stripe subscriber
				if ( $member ) {

					if( ! $member->get_subscription_id() ) {
						die( 'no subscription ID for member' );
					}

					if( $event->type == 'charge.succeeded' || $event->type == 'invoice.payment_succeeded' ) {

						// setup payment data
						$payment_data = array(
							'date'              => date_i18n( 'Y-m-d g:i:s', $event->created ),
							'payment_type' 		=> 'Credit Card',
							'user_id' 			=> $member->ID,
							'amount'            => '',
							'transaction_id'    => '',
						);

						if ( $event->type == 'charge.succeeded' ) {

							// Successful one-time payment
							if ( empty( $payment_event->invoice ) ) {

								$payment_data['amount']         = $payment_event->amount / rcp_stripe_get_currency_multiplier();
								$payment_data['transaction_id'] = $payment_event->id;

							// Successful subscription payment
							} else {

								$invoice = \Stripe\Invoice::retrieve( $payment_event->invoice );
								$payment_data['amount']         = $invoice->amount_due / rcp_stripe_get_currency_multiplier();
								$payment_data['transaction_id'] = $payment_event->id;

							}

						} elseif ( $event->type == 'invoice.payment_succeeded' && empty( $payment_event->charge ) ) {

							$invoice = $payment_event;

							// Successful subscription paid made with account credit, or free trial, where no charge is created
							if ( 'in_' !== substr( $invoice->id, 0, 3 ) ) {
								$payment_data['amount']         = $invoice->amount_due / rcp_stripe_get_currency_multiplier();
								$payment_data['transaction_id'] = $invoice->id;
							} else {
								$payment_data['amount']           = $invoice->lines->data[0]->amount / rcp_stripe_get_currency_multiplier();
								$payment_data['transaction_id']   = $invoice->subscription; // trials don't get a charge ID. set the subscription ID.
								$payment_data['is_trial_invoice'] = true;
							}

						}

						if( ! empty( $payment_data['transaction_id'] ) && ! $rcp_payments->payment_exists( $payment_data['transaction_id'] ) ) {

							if ( ! empty( $invoice->subscription ) ) {

								$customer = \Stripe\Customer::retrieve( $member->get_payment_profile_id() );
								$subscription = $customer->subscriptions->retrieve( $invoice->subscription );

								if ( ! empty( $subscription ) ) {
									$expiration = date( 'Y-m-d 23:59:59', $subscription->current_period_end );
									$member->set_recurring();
								}

								$member->set_merchant_subscription_id( $subscription->id );

							}

							$member->renew( $member->is_recurring(), 'active', $expiration );

							// These must be retrieved after the status is set to active in order for upgrades to work properly
							$payment_data['subscription']     = $member->get_subscription_name();
							$payment_data['subscription_key'] = $member->get_subscription_key();

							// record this payment if it hasn't been recorded yet and it's not a trial invoice
							if ( empty( $payment_data['is_trial_invoice'] ) ) {
								$rcp_payments->insert( $payment_data );
							}

							do_action( 'rcp_stripe_charge_succeeded', $user, $payment_data, $event );

							die( 'rcp_stripe_charge_succeeded action fired successfully' );

						} else {

							die( 'duplicate payment found' );

						}

					}

					// failed payment
					if ( $event->type == 'charge.failed' ) {

						do_action( 'rcp_recurring_payment_failed', $member, $this, $event );
						do_action( 'rcp_stripe_charge_failed', $payment_event, $event );

						die( 'rcp_stripe_charge_failed action fired successfully' );

					}

					// Cancelled / failed subscription
					if( $event->type == 'customer.subscription.deleted' ) {

						if( ! $member->just_upgraded() ) {

							$member->cancel();

							die( 'member cancelled successfully' );

						}

					}

					do_action( 'rcp_stripe_' . $event->type, $payment_event, $event );

				}


			} catch ( Exception $e ) {
				// something failed
				die( 'PHP exception: ' . $e->getMessage() );
			}

			die( '1' );

		}

		die( 'no event ID found' );

	}

	/**
	 * Add credit card fields
	 *
	 * @since 2.1
	 * @return string
	 */
	public function fields() {

		ob_start();
?>
		<script type="text/javascript">

			var rcp_script_options;
			var rcp_processing;
			var rcp_stripe_processing = false;

			// this identifies your website in the createToken call below
			Stripe.setPublishableKey('<?php echo $this->publishable_key; ?>');

			function stripeResponseHandler(status, response) {
				if (response.error) {
					// re-enable the submit button
					jQuery('#rcp_registration_form #rcp_submit').attr("disabled", false);

					jQuery('#rcp_ajax_loading').hide();

					// show the errors on the form
					jQuery('#rcp_registration_form').unblock();
					jQuery('#rcp_submit').before( '<div class="rcp_message error"><p class="rcp_error"><span>' + response.error.message + '</span></p></div>' );
					jQuery('#rcp_submit').val( rcp_script_options.register );

					rcp_stripe_processing = false;
					rcp_processing = false;

				} else {

					var form$ = jQuery('#rcp_registration_form');
					// token contains id, last4, and card type
					var token = response['id'];
					// insert the token into the form so it gets submitted to the server
					form$.append("<input type='hidden' name='stripeToken' value='" + token + "' />");

					// and submit
					form$.get(0).submit();

				}
			}

			jQuery(document).ready(function($) {

				$('body').on('rcp_register_form_submission', function(event, response, form_id) {

					if( ! rcp_stripe_processing ) {

						rcp_stripe_processing = true;

						// get the subscription price
						if( $('.rcp_level:checked').length ) {
							var price = $('.rcp_level:checked').closest('.rcp_subscription_level').find('span.rcp_price').attr('rel') * <?php echo rcp_stripe_get_currency_multiplier(); ?>;
						} else {
							var price = $('.rcp_level').attr('rel') * <?php echo rcp_stripe_get_currency_multiplier(); ?>;
						}

						if( response.gateway.slug === 'stripe' && price > 0 && ! $('.rcp_gateway_fields').hasClass('rcp_discounted_100')) {

							event.preventDefault();

							// disable the submit button to prevent repeated clicks
							$('#rcp_registration_form #rcp_submit').attr("disabled", "disabled");
							$('#rcp_ajax_loading').show();

							// createToken returns immediately - the supplied callback submits the form if there are no errors
							Stripe.createToken({
								number: $('.card-number').val(),
								name: $('.card-name').val(),
								cvc: $('.card-cvc').val(),
								exp_month: $('.card-expiry-month').val(),
								exp_year: $('.card-expiry-year').val(),
								address_zip: $('.card-zip').val()
							}, stripeResponseHandler);

							return false;
						}

					}

				});
			});
		</script>
<?php
		rcp_get_template_part( 'card-form' );
		return ob_get_clean();
	}

	/**
	 * Validate additional fields during registration submission
	 *
	 * @since  2.1
	 * @return void
	 */
	public function validate_fields() {

		global $rcp_options;

		if( empty( $_POST['rcp_card_number'] ) ) {
			rcp_errors()->add( 'missing_card_number', __( 'The card number you have entered is invalid', 'rcp' ), 'register' );
		}

		if( empty( $_POST['rcp_card_cvc'] ) ) {
			rcp_errors()->add( 'missing_card_code', __( 'The security code you have entered is invalid', 'rcp' ), 'register' );
		}

		if( empty( $_POST['rcp_card_zip'] ) ) {
			rcp_errors()->add( 'missing_card_zip', __( 'The zip / postal code you have entered is invalid', 'rcp' ), 'register' );
		}

		if( empty( $_POST['rcp_card_name'] ) ) {
			rcp_errors()->add( 'missing_card_name', __( 'The card holder name you have entered is invalid', 'rcp' ), 'register' );
		}

		if( empty( $_POST['rcp_card_exp_month'] ) ) {
			rcp_errors()->add( 'missing_card_exp_month', __( 'The card expiration month you have entered is invalid', 'rcp' ), 'register' );
		}

		if( empty( $_POST['rcp_card_exp_year'] ) ) {
			rcp_errors()->add( 'missing_card_exp_year', __( 'The card expiration year you have entered is invalid', 'rcp' ), 'register' );
		}

		if ( $this->test_mode && ( empty( $rcp_options['stripe_test_secret'] ) || empty( $rcp_options['stripe_test_publishable'] ) ) ) {
			rcp_errors()->add( 'missing_stripe_test_keys', __( 'Missing Stripe test keys. Please enter your test keys to use Stripe in Sandbox Mode.', 'rcp' ), 'register' );
		}

		if ( ! $this->test_mode && ( empty( $rcp_options['stripe_live_secret'] ) || empty( $rcp_options['stripe_live_publishable'] ) ) ) {
			rcp_errors()->add( 'missing_stripe_live_keys', __( 'Missing Stripe live keys. Please enter your live keys to use Stripe in Live Mode.', 'rcp' ), 'register' );
		}

	}

	/**
	 * Load Stripe JS
	 *
	 * @since 2.1
	 * @return void
	 */
	public function scripts() {
		wp_enqueue_script( 'stripe', 'https://js.stripe.com/v2/', array( 'jquery' ) );
	}

	/**
	 * Create plan in Stripe
	 *
	 * @param string $plan_name Name of the plan.
	 *
	 * @since 2.1
	 * @return bool|string - plan_id if successful, false if not
	 */
	private function create_plan( $plan_name = '' ) {
		global $rcp_options;

		// get all subscription level info for this plan
		$plan           = rcp_get_subscription_details_by_name( $plan_name );
		$price          = round( $plan->price * rcp_stripe_get_currency_multiplier(), 0 );
		$interval       = $plan->duration_unit;
		$interval_count = $plan->duration;
		$name           = $plan->name;
		$plan_id        = sprintf( '%s-%s-%s', strtolower( str_replace( ' ', '', $plan_name ) ), $plan->price, $plan->duration . $plan->duration_unit );
		$currency       = strtolower( rcp_get_currency() );

		\Stripe\Stripe::setApiKey( $this->secret_key );

		try {

			$plan = \Stripe\Plan::create( array(
				"amount"         => $price,
				"interval"       => $interval,
				"interval_count" => $interval_count,
				"name"           => $name,
				"currency"       => $currency,
				"id"             => $plan_id
			) );

			// plann successfully created
			return $plan->id;

		} catch ( Exception $e ) {

			$this->handle_processing_error( $e );
		}

	}

	/**
	 * Determine if a plan exists
	 *
	 * @param string $plan The name of the plan to check
	 *
	 * @since 2.1
	 * @return bool|string false if the plan doesn't exist, plan id if it does
	 */
	private function plan_exists( $plan ) {

		\Stripe\Stripe::setApiKey( $this->secret_key );

		if ( ! $plan = rcp_get_subscription_details_by_name( $plan ) ) {
			return false;
		}

		// fallback to old plan id if the new plan id does not exist
		$old_plan_id = strtolower( str_replace( ' ', '', $plan->name ) );
		$new_plan_id = sprintf( '%s-%s-%s', $old_plan_id, $plan->price, $plan->duration . $plan->duration_unit );

		// check if the plan new plan id structure exists
		try {

			$plan = \Stripe\Plan::retrieve( $new_plan_id );
			return $plan->id;

		} catch ( Exception $e ) {}

		try {
			// fall back to the old plan id structure and verify that the plan metadata also matches
			$stripe_plan = \Stripe\Plan::retrieve( $old_plan_id );

			if ( (int) $stripe_plan->amount !== (int) $plan->price * 100 ) {
				return false;
			}

			if ( $stripe_plan->interval !== $plan->duration_unit ) {
				return false;
			}

			if ( $stripe_plan->interval_count !== intval( $plan->duration ) ) {
				return false;
			}

			return $old_plan_id;

		} catch ( Exception $e ) {
			return false;
		}

	}

}
