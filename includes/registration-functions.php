<?php
/**
 * Registration Functions
 *
 * Processes the registration form
 *
 * @package     Restrict Content Pro
 * @subpackage  Registration Functions
 * @copyright   Copyright (c) 2017, Pippin Williamson
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       1.5
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;


/**
 * Register a new user
 *
 * @access public
 * @since  1.0
 * @return void
 */
function rcp_process_registration() {

	// check nonce
	if ( ! ( isset( $_POST["rcp_register_nonce"] ) && wp_verify_nonce( $_POST['rcp_register_nonce'], 'rcp-register-nonce' ) ) ) {
		return;
	}

	global $rcp_options, $rcp_levels_db;

	$subscription_id     = rcp_get_registration()->get_subscription();
	$discount            = isset( $_POST['rcp_discount'] ) ? sanitize_text_field( $_POST['rcp_discount'] ) : '';
	$price               = number_format( (float) $rcp_levels_db->get_level_field( $subscription_id, 'price' ), 2 );
	$price               = str_replace( ',', '', $price );
	$subscription        = $rcp_levels_db->get_level( $subscription_id );
	$auto_renew          = rcp_registration_is_recurring();
	$trial_duration      = $rcp_levels_db->trial_duration( $subscription_id );
	$trial_duration_unit = $rcp_levels_db->trial_duration_unit( $subscription_id );
	// if both today's total and the recurring total are 0, the there is a full discount
	// if this is not a recurring subscription only check today's total
	$full_discount = ( $auto_renew ) ? ( rcp_get_registration()->get_total() == 0 && rcp_get_registration()->get_recurring_total() == 0 ) : ( rcp_get_registration()->get_total() == 0 );

	// get the selected payment method/gateway
	if( ! isset( $_POST['rcp_gateway'] ) ) {
		$gateway = 'paypal';
	} else {
		$gateway = sanitize_text_field( $_POST['rcp_gateway'] );
	}

	/***********************
	* validate the form
	***********************/

	do_action( 'rcp_before_form_errors', $_POST );

	$is_ajax   = isset( $_POST['rcp_ajax'] );

	$user_data = rcp_validate_user_data();

	if( ! rcp_is_registration() ) {
		// no subscription level was chosen
		rcp_errors()->add( 'no_level', __( 'Please choose a subscription level', 'rcp' ), 'register' );
	}

	if( $subscription_id && $price == 0 && $subscription->duration > 0 && rcp_has_used_trial( $user_data['id'] ) ) {
		// this ensures that users only sign up for a free trial once
		rcp_errors()->add( 'free_trial_used', __( 'You may only sign up for a free trial once', 'rcp' ), 'register' );
	}

	if( ! empty( $discount ) ) {

		// make sure we have a valid discount
		if( rcp_validate_discount( $discount, $subscription_id ) ) {

			// check if the user has already used this discount
			if ( $price > 0 && ! $user_data['need_new'] && rcp_user_has_used_discount( $user_data['id'] , $discount ) && apply_filters( 'rcp_discounts_once_per_user', false, $discount, $subscription_id ) ) {
				rcp_errors()->add( 'discount_already_used', __( 'You can only use the discount code once', 'rcp' ), 'register' );
			}

		} else {
			// the entered discount code is incorrect
			rcp_errors()->add( 'invalid_discount', __( 'The discount you entered is invalid', 'rcp' ), 'register' );
		}

	}

	// Validate extra fields in gateways with the 2.1+ gateway API
	if( ! has_action( 'rcp_gateway_' . $gateway ) && $price > 0 && ! $full_discount ) {

		$gateways    = new RCP_Payment_Gateways;
		$gateway_var = $gateways->get_gateway( $gateway );
		$gateway_obj = new $gateway_var['class'];
		$gateway_obj->validate_fields();
	}

	do_action( 'rcp_form_errors', $_POST );

	// retrieve all error messages, if any
	$errors = rcp_errors()->get_error_messages();

	if ( ! empty( $errors ) && $is_ajax ) {
		wp_send_json_error( array(
			'success'          => false,
			'errors'           => rcp_get_error_messages_html( 'register' ),
			'nonce'            => wp_create_nonce( 'rcp-register-nonce' ),
			'gateway'          => array(
				'slug'     => $gateway,
				'supports' => ! empty( $gateway_obj->supports ) ? $gateway_obj->supports : false
			)
		) );

	} elseif( $is_ajax ) {
		wp_send_json_success( array(
			'success'          => true,
			'total'            => rcp_get_registration()->get_total(),
			'gateway'          => array(
				'slug'     => $gateway,
				'supports' => ! empty( $gateway_obj->supports ) ? $gateway_obj->supports : false
			),
			'level'            => array(
				'trial'        => ! empty( $trial_duration )
			)
		) );

	}

	// only create the user if there are no errors
	if( ! empty( $errors ) ) {
		return;
	}

	if( $user_data['need_new'] ) {

		$display_name = trim( $user_data['first_name'] . ' ' . $user_data['last_name'] );

		$user_data['id'] = wp_insert_user( array(
				'user_login'      => $user_data['login'],
				'user_pass'       => $user_data['password'],
				'user_email'      => $user_data['email'],
				'first_name'      => $user_data['first_name'],
				'last_name'       => $user_data['last_name'],
				'display_name'    => ! empty( $display_name ) ? $display_name : $user_data['login'],
				'user_registered' => date( 'Y-m-d H:i:s' )
			)
		);

	}

	if ( empty( $user_data['id'] ) ) {
		return;
	}

	// Setup the member object
	$member = new RCP_Member( $user_data['id'] );

	update_user_meta( $user_data['id'], '_rcp_new_subscription', '1' );

	$subscription_key = rcp_generate_subscription_key();

	$old_subscription_id = $member->get_subscription_id();

	$member_has_trialed = $member->has_trialed();

	if( $old_subscription_id ) {
		update_user_meta( $user_data['id'], '_rcp_old_subscription_id', $old_subscription_id );
	}

	if( ! $member->is_active() ) {

		update_user_meta( $user_data['id'], 'rcp_subscription_level', $subscription_id );
		update_user_meta( $user_data['id'], 'rcp_subscription_key', $subscription_key );

		// Ensure no pending level details are set
		delete_user_meta( $user_data['id'], 'rcp_pending_subscription_level' );
		delete_user_meta( $user_data['id'], 'rcp_pending_subscription_key' );

		$member->set_status( 'pending' );

	} else {

		// If the member is already active, we need to set these as pending changes
		update_user_meta( $user_data['id'], 'rcp_pending_subscription_level', $subscription_id );
		update_user_meta( $user_data['id'], 'rcp_pending_subscription_key', $subscription_key );

		// Flag the member as having just upgraded
		update_user_meta( $user_data['id'], '_rcp_just_upgraded', current_time( 'timestamp' ) );

	}

	$member->set_joined_date( '', $subscription_id );

	// Calculate the expiration date for the member
	$member_expires = $member->calculate_expiration( $auto_renew, $trial_duration );

	update_user_meta( $user_data['id'], 'rcp_pending_expiration_date', $member_expires );


	// remove the user's old role, if this is a new user, we need to replace the default role
	$old_role = get_option( 'default_role', 'subscriber' );

	if ( $old_subscription_id ) {
		$old_level = $rcp_levels_db->get_level( $old_subscription_id );
		$old_role  = ! empty( $old_level->role ) ? $old_level->role : $old_role;
	}

	$member->remove_role( $old_role );

	// Set the user's role
	$role = ! empty( $subscription->role ) ? $subscription->role : 'subscriber';
	$user = new WP_User( $user_data['id'] );
	$user->add_role( apply_filters( 'rcp_default_user_level', $role, $subscription_id ) );

	do_action( 'rcp_form_processing', $_POST, $user_data['id'], $price );

	// process a paid subscription
	if( $price > '0' || $trial_duration ) {

		if( ! empty( $discount ) ) {

			$discounts    = new RCP_Discounts();
			$discount_obj = $discounts->get_by( 'code', $discount );

			// record the usage of this discount code
			$discounts->add_to_user( $user_data['id'], $discount );

			// increase the usage count for the code
			$discounts->increase_uses( $discount_obj->id );

			// if the discount is 100%, log the user in and redirect to success page
			if( $full_discount ) {
				$member->set_expiration_date( $member_expires );
				$member->set_status( 'active' );
				rcp_login_user_in( $user_data['id'], $user_data['login'] );
				wp_redirect( rcp_get_return_url( $user_data['id'] ) ); exit;
			}

		}

		// Remove trialing status, if it exists
		if ( ! $trial_duration || $trial_duration && $member_has_trialed ) {
			delete_user_meta( $user_data['id'], 'rcp_is_trialing' );
		} else {
			update_user_meta( $user_data['id'], 'rcp_has_trialed', 'yes' );
			update_user_meta( $user_data['id'], 'rcp_is_trialing', 'yes' );
		}

		// log the new user in
		rcp_login_user_in( $user_data['id'], $user_data['login'] );

		$redirect = rcp_get_return_url( $user_data['id'] );

		$subscription_data = array(
			'price'               => rcp_get_registration()->get_total( true, false ), // get total without the fee
			'discount'            => rcp_get_registration()->get_total_discounts(),
			'discount_code'       => $discount,
			'fee'                 => rcp_get_registration()->get_total_fees(),
			'length'              => $subscription->duration,
			'length_unit'         => strtolower( $subscription->duration_unit ),
			'subscription_id'     => $subscription->id,
			'subscription_name'   => $subscription->name,
			'key'                 => $subscription_key,
			'user_id'             => $user_data['id'],
			'user_name'           => $user_data['login'],
			'user_email'          => $user_data['email'],
			'currency'            => rcp_get_currency(),
			'auto_renew'          => $auto_renew,
			'return_url'          => $redirect,
			'new_user'            => $user_data['need_new'],
			'trial_duration'      => $trial_duration,
			'trial_duration_unit' => $trial_duration_unit,
			'trial_eligible'      => ! $member_has_trialed,
			'post_data'           => $_POST
		);

		// if giving the user a credit, make sure the credit does not exceed the first payment
		if ( $subscription_data['fee'] < 0 && abs( $subscription_data['fee'] ) > $subscription_data['price'] ) {
			$subscription_data['fee'] = -1 * $subscription_data['price'];
		}

		update_user_meta( $user_data['id'], 'rcp_pending_subscription_amount', $subscription_data['price'] + $subscription_data['fee'] );

		// send all of the subscription data off for processing by the gateway
		rcp_send_to_gateway( $gateway, apply_filters( 'rcp_subscription_data', $subscription_data ) );

	// process a free or trial subscription
	} else {

		// This is a free user registration or trial
		$member->set_expiration_date( $member_expires );

		// if the subscription is a free trial, we need to record it in the user meta
		if( $member_expires != 'none' ) {

			// this is so that users can only sign up for one trial
			update_user_meta( $user_data['id'], 'rcp_has_trialed', 'yes' );
			update_user_meta( $user_data['id'], 'rcp_is_trialing', 'yes' );

			// activate the user's trial subscription
			$member->set_status( 'active' );

		} else {

			update_user_meta( $user_data['id'], 'rcp_subscription_level', $subscription_id );
			update_user_meta( $user_data['id'], 'rcp_subscription_key', $subscription_key );

			// Ensure no pending level details are set
			delete_user_meta( $user_data['id'], 'rcp_pending_subscription_level' );
			delete_user_meta( $user_data['id'], 'rcp_pending_subscription_key' );

			// set the user's status to free
			$member->set_status( 'free' );
			rcp_email_subscription_status( $user_data['id'], 'free' );

		}

		if( $user_data['need_new'] ) {

			if( ! isset( $rcp_options['disable_new_user_notices'] ) ) {

				// send an email to the admin alerting them of the registration
				wp_new_user_notification( $user_data['id']) ;

			}

			// log the new user in
			rcp_login_user_in( $user_data['id'], $user_data['login'] );

		}
		// send the newly created user to the redirect page after logging them in
		wp_redirect( rcp_get_return_url( $user_data['id'] ) ); exit;

	} // end price check

}
add_action( 'init', 'rcp_process_registration', 100 );
add_action( 'wp_ajax_rcp_process_register_form', 'rcp_process_registration', 100 );
add_action( 'wp_ajax_nopriv_rcp_process_register_form', 'rcp_process_registration', 100 );

/**
 * Provide the default registration values when checking out with Stripe Checkout.
 *
 * @return void
 */
function rcp_handle_stripe_checkout() {

	if ( isset( $_POST['rcp_ajax'] ) || empty( $_POST['rcp_gateway'] ) || empty( $_POST['stripeEmail'] ) || 'stripe_checkout' !== $_POST['rcp_gateway'] ) {
		return;
	}

	if ( empty( $_POST['rcp_user_email'] ) ) {
		$_POST['rcp_user_email'] = $_POST['stripeEmail'];
	}

	if ( empty( $_POST['rcp_user_login'] ) ) {
		$_POST['rcp_user_login'] = $_POST['stripeEmail'];
	}

	if ( empty( $_POST['rcp_user_first'] ) ) {
		$user_email = explode( '@', $_POST['rcp_user_email'] );
		$_POST['rcp_user_first'] = $user_email[0];
	}

	if ( empty( $_POST['rcp_user_last'] ) ) {
		$_POST['rcp_user_last'] = '';
	}

	if ( empty( $_POST['rcp_user_pass'] ) ) {
		$_POST['rcp_user_pass'] = wp_generate_password();
	}

	if ( empty( $_POST['rcp_user_pass_confirm'] ) ) {
		$_POST['rcp_user_pass_confirm'] = $_POST['rcp_user_pass'];
	}

}
add_action( 'rcp_before_form_errors', 'rcp_handle_stripe_checkout' );


/**
 * Validate and setup the user data for registration
 *
 * @access      public
 * @since       1.5
 * @return      array
 */
function rcp_validate_user_data() {

	$user = array();

	if( ! is_user_logged_in() ) {
		$user['id']		          = 0;
		$user['login']		      = sanitize_text_field( $_POST['rcp_user_login'] );
		$user['email']		      = sanitize_text_field( $_POST['rcp_user_email'] );
		$user['first_name'] 	  = sanitize_text_field( $_POST['rcp_user_first'] );
		$user['last_name']	 	  = sanitize_text_field( $_POST['rcp_user_last'] );
		$user['password']		  = sanitize_text_field( $_POST['rcp_user_pass'] );
		$user['password_confirm'] = sanitize_text_field( $_POST['rcp_user_pass_confirm'] );
		$user['need_new']         = true;
	} else {
		$userdata 		  = get_userdata( get_current_user_id() );
		$user['id']       = $userdata->ID;
		$user['login'] 	  = $userdata->user_login;
		$user['email'] 	  = $userdata->user_email;
		$user['need_new'] = false;
	}


	if( $user['need_new'] ) {
		if( username_exists( $user['login'] ) ) {
			// Username already registered
			rcp_errors()->add( 'username_unavailable', __( 'Username already taken', 'rcp' ), 'register' );
		}
		if( ! rcp_validate_username( $user['login'] ) ) {
			// invalid username
			rcp_errors()->add( 'username_invalid', __( 'Invalid username', 'rcp' ), 'register' );
		}
		if( empty( $user['login'] ) ) {
			// empty username
			rcp_errors()->add( 'username_empty', __( 'Please enter a username', 'rcp' ), 'register' );
		}
		if( ! is_email( $user['email'] ) ) {
			//invalid email
			rcp_errors()->add( 'email_invalid', __( 'Invalid email', 'rcp' ), 'register' );
		}
		if( email_exists( $user['email'] ) ) {
			//Email address already registered
			rcp_errors()->add( 'email_used', __( 'Email already registered', 'rcp' ), 'register' );
		}
		if( empty( $user['password'] ) ) {
			// passwords do not match
			rcp_errors()->add( 'password_empty', __( 'Please enter a password', 'rcp' ), 'register' );
		}
		if( $user['password'] !== $user['password_confirm'] ) {
			// passwords do not match
			rcp_errors()->add( 'password_mismatch', __( 'Passwords do not match', 'rcp' ), 'register' );
		}
	}

	return apply_filters( 'rcp_user_registration_data', $user );
}


/**
 * Get the registration success/return URL
 *
 * @param       $user_id int The user ID we have just registered
 *
 * @access      public
 * @since       1.5
 * @return      string
 */
function rcp_get_return_url( $user_id = 0 ) {

	global $rcp_options;

	if( isset( $rcp_options['redirect'] ) ) {
		$redirect = get_permalink( $rcp_options['redirect'] );
	} else {
		$redirect = home_url();
	}
	return apply_filters( 'rcp_return_url', $redirect, $user_id );
}

/**
 * Determine if the current page is a registration page
 *
 * @access      public
 * @since       2.0
 * @return      bool
 */
function rcp_is_registration_page() {

	global $rcp_options, $post;

	$ret = false;

	if ( isset( $rcp_options['registration_page'] ) ) {
		$ret = is_page( $rcp_options['registration_page'] );
	}

	if ( ! empty( $post ) && has_shortcode( $post->post_content, 'register_form' ) ) {
		$ret = true;
	}

	return apply_filters( 'rcp_is_registration_page', $ret );
}

/**
 * Get the auto renew behavior
 *
 * 1 == All subscriptions auto renew
 * 2 == No subscriptions auto renew
 * 3 == Customer chooses whether to auto renew
 *
 * @access      public
 * @since       2.0
 * @return      int
 */
function rcp_get_auto_renew_behavior() {

	global $rcp_options, $rcp_level;


	// Check for old disable auto renew option
	if( isset( $rcp_options['disable_auto_renew'] ) ) {
		$rcp_options['auto_renew'] = '2';
		unset( $rcp_options['disable_auto_renew'] );
		update_option( 'rcp_settings', $rcp_options );
	}

	$behavior = isset( $rcp_options['auto_renew'] ) ? $rcp_options['auto_renew'] : '3';

	if( $rcp_level ) {
		$level = rcp_get_subscription_details( $rcp_level );
		if( $level->price == '0' ) {
			$behavior = '2';
		}
	}

	return apply_filters( 'rcp_auto_renew_behavior', $behavior );
}

/**
 * When new subscriptions are registered, a flag is set
 *
 * This removes the flag as late as possible so other systems can hook into
 * rcp_set_status and perform actions on new subscriptions
 *
 * @param string $status  User's membership status.
 * @param int    $user_id ID of the member.
 *
 * @access      public
 * @since       2.3.6
 * @return      void
 */
function rcp_remove_new_subscription_flag( $status, $user_id ) {

	if( 'active' !== $status ) {
		return;
	}

	delete_user_meta( $user_id, '_rcp_old_subscription_id' );
	delete_user_meta( $user_id, '_rcp_new_subscription' );
}
add_action( 'rcp_set_status', 'rcp_remove_new_subscription_flag', 999999999999, 2 );

/**
 * When upgrading subscriptions, the new level / key are stored as pending. Once payment is received, the pending
 * values are set as the permanent values.
 *
 * See https://github.com/restrictcontentpro/restrict-content-pro/issues/294
 *
 * @param string     $status     User's membership status.
 * @param int        $user_id    ID of the user.
 * @param string     $old_status Previous membership status.
 * @param RCP_Member $member     Member object.
 *
 * @access      public
 * @since       2.4.3
 * @return      void
 */
function rcp_set_pending_subscription_on_upgrade( $status, $user_id, $old_status, $member ) {

	if( 'active' !== $status ) {
		return;
	}

	$subscription_id  = get_user_meta( $user_id, 'rcp_pending_subscription_level', true );
	$subscription_key = get_user_meta( $user_id, 'rcp_pending_subscription_key', true );

	if( ! empty( $subscription_id ) && ! empty( $subscription_key ) ) {

		update_user_meta( $user_id, 'rcp_subscription_level', $subscription_id );
		update_user_meta( $user_id, 'rcp_subscription_key', $subscription_key );

		delete_user_meta( $user_id, 'rcp_pending_subscription_level' );
		delete_user_meta( $user_id, 'rcp_pending_subscription_key' );

	}
}
add_action( 'rcp_set_status', 'rcp_set_pending_subscription_on_upgrade', 10, 4 );

/**
 * Adjust subscription member counts on status changes
 *
 * @param string     $status     User's membership status.
 * @param int        $user_id    ID of the user.
 * @param string     $old_status Previous membership status.
 * @param RCP_Member $member     Member object.
 *
 * @access      public
 * @since       2.6
 * @return      void
 */
function rcp_increment_subscription_member_count_on_status_change( $status, $user_id, $old_status, $member ) {

	$pending_sub_id = $member->get_pending_subscription_id();
	$old_sub_id     = get_user_meta( $user_id, '_rcp_old_subscription_id', true );
	$sub_id         = $member->get_subscription_id();

	if( $old_sub_id && (int) $sub_id === (int) $old_sub_id && $status === $old_status ) {
		return;
	}

	if( ! empty( $pending_sub_id ) ) {

		rcp_increment_subscription_member_count( $pending_sub_id, $status );

	} elseif( $status !== $old_status ) {

		rcp_increment_subscription_member_count( $sub_id, $status );

	}

	if( ! empty( $old_status ) && $old_status !== $status ) {
		rcp_decrement_subscription_member_count( $sub_id, $old_status );
	}

	if( $old_sub_id ) {
		rcp_decrement_subscription_member_count( $old_sub_id, $old_status );
	}

}
add_action( 'rcp_set_status', 'rcp_increment_subscription_member_count_on_status_change', 9, 4 );

/**
 * Determine if this registration is recurring
 *
 * @since 2.5
 * @return bool
 */
function rcp_registration_is_recurring() {

	$auto_renew = false;

	if ( '3' == rcp_get_auto_renew_behavior() ) {
		$auto_renew = isset( $_POST['rcp_auto_renew'] );
	}

	if ( '1' == rcp_get_auto_renew_behavior() ) {
		$auto_renew = true;
	}

	// make sure this gateway supports recurring payments
	if ( $auto_renew && ! empty( $_POST['rcp_gateway'] ) ) {
		$auto_renew = rcp_gateway_supports( sanitize_text_field( $_POST['rcp_gateway'] ), 'recurring' );
	}

	if ( $auto_renew && ! empty( $_POST['rcp_level'] ) ) {
		$details = rcp_get_subscription_details( $_POST['rcp_level'] );

		// check if this is an unlimited or free subscription
		if ( empty( $details->duration ) || empty( $details->price ) ) {
			$auto_renew = false;
		}
	}

	if( ! rcp_get_registration_recurring_total() > 0 ) {
		$auto_renew = false;
	}

	return apply_filters( 'rcp_registration_is_recurring', $auto_renew );

}

/**
 * Add the registration total before the gateway fields
 *
 * @since 2.5
 * @return void
 */
function rcp_registration_total_field() {
	?>
	<div class="rcp_registration_total"></div>
<?php
}
add_action( 'rcp_after_register_form_fields', 'rcp_registration_total_field' );

/**
 * Get formatted total for this registration
 *
 * @param bool $echo Whether or not to echo the value.
 *
 * @since      2.5
 * @return string|bool|void
 */
function rcp_registration_total( $echo = true ) {
	$total = rcp_get_registration_total();

	// the registration has not been setup yet
	if ( false === $total ) {
		return false;
	}

	if ( 0 < $total ) {
		$total = number_format( $total, rcp_currency_decimal_filter() );
		$total = rcp_currency_filter( $total );
	} else {
		$total = __( 'free', 'rcp' );
	}

	global $rcp_levels_db;

	$level               = $rcp_levels_db->get_level( rcp_get_registration()->get_subscription() );
	$trial_duration      = $rcp_levels_db->trial_duration( $level->id );
	$trial_duration_unit = $rcp_levels_db->trial_duration_unit( $level->id );

	if ( ! empty( $trial_duration ) && ! rcp_has_used_trial() ) {
		$total_price = $total;
		$total = sprintf( __( 'Free trial - %s', 'rcp' ), $trial_duration . ' ' .  rcp_filter_duration_unit( $trial_duration_unit, $trial_duration ) );
		$total .= ', ' . $total_price . ' due after trial period.';
	}

	$total = apply_filters( 'rcp_registration_total', $total );

	if ( $echo ) {
		echo $total;
	}

	return $total;
}

/**
 * Get the total for this registration
 *
 * @since  2.5
 * @return float|false
 */
function rcp_get_registration_total() {

	if ( ! rcp_is_registration() ) {
		return false;
	}

	return rcp_get_registration()->get_total();
}

/**
 * Get formatted recurring total for this registration
 *
 * @param bool $echo Whether or not to echo the value.
 *
 * @since  2.5
 * @return string|bool|void
 */
function rcp_registration_recurring_total( $echo = true ) {
	$total = rcp_get_registration_recurring_total();

	// the registration has not been setup yet
	if ( false === $total ) {
		return false;
	}

	if ( 0 < $total ) {
		$total = number_format( $total, rcp_currency_decimal_filter() );
		$total = rcp_currency_filter( $total );
		$subscription = rcp_get_subscription_details( rcp_get_registration()->get_subscription() );

		if ( $subscription->duration == 1 ) {
			$total .= '/' . rcp_filter_duration_unit( $subscription->duration_unit, 1 );
		} else {
			$total .= sprintf( __( ' every %s %s', 'rcp' ), $subscription->duration, rcp_filter_duration_unit( $subscription->duration_unit, $subscription->duration ) );
		}
	} else {
		$total = __( 'free', 'rcp' );;
	}

	$total = apply_filters( 'rcp_registration_recurring_total', $total );

	if ( $echo ) {
		echo $total;
	}

	return $total;
}

/**
 * Get the recurring total payment
 *
 * @since 2.5
 * @return bool|int
 */
function rcp_get_registration_recurring_total() {

	if ( ! rcp_is_registration() ) {
		return false;
	}

	return rcp_get_registration()->get_recurring_total();
}

/**
 * Is the registration object setup?
 *
 * @since 2.5
 * @return bool
 */
function rcp_is_registration() {
	return (bool) rcp_get_registration()->get_subscription();
}

/**
 * Get the registration object. If it hasn't been setup, setup an empty
 * registration object.
 *
 * @return RCP_Registration
 */
function rcp_get_registration() {
	global $rcp_registration;

	// setup empty registration object if one doesn't exist
	if ( ! is_a( $rcp_registration, 'RCP_Registration' ) ) {
		rcp_setup_registration();
	}

	return $rcp_registration;
}

/**
 * Setup the registration object
 *
 * Auto setup cart on page load if $_POST parameters are found
 *
 * @param int|null    $level_id ID of the subscription level for this registration.
 * @param string|null $discount Discount code to apply to this registration.
 *
 * @since  2.5
 * @return void
 */
function rcp_setup_registration( $level_id = null, $discount = null ) {
	global $rcp_registration;

	$rcp_registration = new RCP_Registration( $level_id, $discount );
	do_action( 'rcp_setup_registration', $level_id, $discount );
}

/**
 * Automatically setup the registration object
 *
 * @uses rcp_setup_registration()
 *
 * @return void
 */
function rcp_setup_registration_init() {

	if ( empty( $_POST['rcp_level'] ) ) {
		return;
	}

	$level_id = abs( $_POST['rcp_level'] );
	$discount = ! empty( $_REQUEST['discount'] ) ? sanitize_text_field( $_REQUEST['discount'] ) : null;
	$discount = ! empty( $_POST['rcp_discount'] ) ? sanitize_text_field( $_POST['rcp_discount'] ) : $discount;

	rcp_setup_registration( $level_id, $discount );
}
add_action( 'init', 'rcp_setup_registration_init' );


/**
 * Filter levels to only show valid upgrade levels
 *
 * @since 2.5
 * @return array Array of subscriptions.
 */
function rcp_filter_registration_upgrade_levels() {

	remove_filter( 'rcp_get_levels', 'rcp_filter_registration_upgrade_levels' );

	$levels = rcp_get_upgrade_paths();

	add_filter( 'rcp_get_levels', 'rcp_filter_registration_upgrade_levels' );

	return $levels;

}

/**
 * Hook into registration page and filter upgrade path
 */
add_action( 'rcp_before_subscription_form_fields', 'rcp_filter_registration_upgrade_levels' );

/**
 * Add prorate credit to member registration
 *
 * @param RCP_Registration $registration
 *
 * @since 2.5
 * @return void
 */
function rcp_add_prorate_fee( $registration ) {
	if ( ! $amount = rcp_get_member_prorate_credit() ) {
		return;
	}

	$registration->add_fee( -1 * $amount, __( 'Proration Credit', 'rcp' ), false, true );
}
add_action( 'rcp_registration_init', 'rcp_add_prorate_fee' );

/**
 * Add message to checkout specifying proration credit
 *
 * @since 2.5
 * @return void
 */
function rcp_add_prorate_message() {
	if ( ! $amount = rcp_get_member_prorate_credit() ) {
		return;
	}

	$prorate_message = sprintf( '<p>%s</p>', __( 'If you upgrade or downgrade your account, the new subscription will be prorated up to %s for the first payment. Prorated prices are shown below.', 'rcp' ) );

	printf( apply_filters( 'rcp_registration_prorate_message', $prorate_message ), esc_html( rcp_currency_filter( $amount ) ) );
}
add_action( 'rcp_before_subscription_form_fields', 'rcp_add_prorate_message' );

/**
 * Removes the _rcp_expiring_soon_email_sent user meta flag when the member's status is set to active.
 *
 * @param string $status  User's membership status.
 * @param int    $user_id ID of the user.
 *
 * @since 2.5.5
 * @return void
 */
function rcp_remove_expiring_soon_email_sent_flag( $status, $user_id ) {

	if( 'active' !== $status ) {
		return;
	}

	delete_user_meta( $user_id, '_rcp_expiring_soon_email_sent' );
}
add_action( 'rcp_set_status', 'rcp_remove_expiring_soon_email_sent_flag', 10, 2 );
