<?php
/**
 * RCP Registration Class
 *
 * @package     Restrict Content Pro
 * @subpackage  Classes/Registration
 * @copyright   Copyright (c) 2017, Restrict Content Pro
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       2.5
 */

class RCP_Registration {

	/**
	 * Store the subscription for the registration
	 *
	 * @since 2.5
	 * @var int
	 */
	protected $subscription = 0;

	/**
	 * Store the discounts for the registration
	 *
	 * @since 2.5
	 * @var array
	 */
	protected $discounts = array();

	/**
	 * Store the fees/credits for the registration. Credits are negative fees.
	 *
	 * @since 2.5
	 * @var array
	 */
	protected $fees = array();

	/**
	 * Get things started.
	 *
	 * @param int         $level_id ID of the subscription level for this registration.
	 * @param null|string $discount Discount code to apply to this registration.
	 *
	 * @return void
	 */
	public function __construct( $level_id = 0, $discount = null ) {

		if ( ! $level_id ) {
			return;
		}

		$this->set_subscription( $level_id );

		if ( $discount ) {
			$this->add_discount( strtolower( $discount ) );
		}

		do_action( 'rcp_registration_init', $this );
	}

	/**
	 * Set the subscription for this registration
	 *
	 * @since 2.5
	 * @param $subscription_id
	 *
	 * @return bool
	 */
	public function set_subscription( $subscription_id ) {
		if ( ! $subscription = rcp_get_subscription_details( $subscription_id ) ) {
			return false;
		}

		$this->subscription = $subscription_id;

		if ( $subscription->fee ) {
			$description = ( $subscription->fee > 0 ) ? __( 'Signup Fee', 'rcp' ) : __( 'Signup Credit', 'rcp' );
			$this->add_fee( $subscription->fee, $description );
		}

		return true;
	}

	/**
	 * Get registration subscription
	 *
	 * @since 2.5
	 * @return int
	 */
	public function get_subscription() {
		return $this->subscription;
	}

	/**
	 * Add discount to the registration
	 *
	 * @since      2.5
	 * @param      $code
	 * @param bool $recurring
	 *
	 * @return bool
	 */
	public function add_discount( $code, $recurring = true ) {
		if ( ! rcp_validate_discount( $code, $this->subscription ) ) {
			return false;
		}

		$this->discounts[ $code ] = $recurring;
		return true;
	}

	/**
	 * Get registration discounts
	 *
	 * @since 2.5
	 * @return array|bool
	 */
	public function get_discounts() {
		if ( empty( $this->discounts ) ) {
			return false;
		}

		return $this->discounts;
	}

	/**
	 * Add fee to the registration. Use negative fee for credit.
	 *
	 * @since      2.5
	 * @param float $amount
	 * @param null $description
	 * @param bool $recurring
	 * @param bool $proration
	 *
	 * @return bool
	 */
	public function add_fee( $amount, $description = null, $recurring = false, $proration = false ) {

		$fee = array(
			'amount'     => floatval( $amount ),
			'description'=> sanitize_text_field( $description ),
			'recurring'  => (bool) $recurring,
			'proration'  => (bool) $proration,
		);

		$id = md5( serialize( $fee ) );

		if ( isset( $this->fees[ $id ] ) ) {
			return false;
		}

		$this->fees[ $id ] = apply_filters( 'rcp_registration_add_fee', $fee, $this );

		return true;
	}

	/**
	 * Get registration fees
	 *
	 * @since 2.5
	 * @return array|bool
	 */
	public function get_fees() {
		if ( empty( $this->fees ) ) {
			return false;
		}

		return $this->fees;
	}

	/**
	 * Get the total number of fees
	 *
	 * @since 2.5
	 * @param null $total
	 * @param bool $only_recurring | set to only get fees that are recurring
	 *
	 * @return float
	 */
	public function get_total_fees( $total = null, $only_recurring = false ) {

		if ( ! $this->get_fees() ) {
			return 0;
		}

		$fees = 0;

		foreach( $this->get_fees() as $fee ) {
			if ( $only_recurring && ! $fee['recurring'] ) {
				continue;
			}

			$fees += $fee['amount'];
		}

		// if total is present, make sure that any negative fees are not
		// greater than the total.
		if ( $total && ( $fees + $total ) < 0 ) {
			$fees = -1 * $total;
		}

		return apply_filters( 'rcp_registration_get_total_fees', (float) $fees, $total, $only_recurring, $this );

	}

	/**
	 * Get the signup fees
	 *
	 * @since 2.5
	 * @param null $total
	 *
	 * @return float
	 */
	public function get_signup_fees( ) {

		if ( ! $this->get_fees() ) {
			return 0;
		}

		$fees = 0;

		foreach( $this->get_fees() as $fee ) {

			if ( $fee['proration'] ) {
				continue;
			}

			if ( $fee['recurring'] ) {
				continue;
			}

			$fees += $fee['amount'];
		}

		return apply_filters( 'rcp_registration_get_signup_fees', (float) $fees, $this );

	}

	/**
	 * Get the total proration amount
	 *
	 * @since 2.5
	 *
	 * @return float
	 */
	public function get_proration_credits() {

		if ( ! $this->get_fees() ) {
			return 0;
		}

		$proration = 0;

		foreach( $this->get_fees() as $fee ) {

			if ( ! $fee['proration'] ) {
				continue;
			}

			$proration += $fee['amount'];

		}

		return apply_filters( 'rcp_registration_get_proration_fees', (float) $proration, $this );

	}

	/**
	 * Get the total discounts
	 *
	 * @since 2.5
	 * @param null $total
	 * @param bool $only_recurring | set to only get discounts that are recurring
	 *
	 * @return int|mixed
	 */
	public function get_total_discounts( $total = null, $only_recurring = false ) {

		if ( ! $registration_discounts = $this->get_discounts() ) {
			return 0;
		}

		if ( ! $total ) {
			$total = rcp_get_subscription_price( $this->subscription );
		}

		$original_total = $total;

		foreach( $registration_discounts as $registration_discount => $recurring ) {

			if ( $only_recurring && ! $recurring ) {
				continue;
			}

			$discounts    = new RCP_Discounts();
			$discount_obj = $discounts->get_by( 'code', $registration_discount );

			if ( is_object( $discount_obj ) ) {
				// calculate the after-discount price
				$total = $discounts->calc_discounted_price( $total, $discount_obj->amount, $discount_obj->unit );
			}
		}

		// make sure the discount is not > 100%
		// if ( 0 > $total ) {
		// 	$total = 0;
		// }

		return apply_filters( 'rcp_registration_get_total_discounts', (float) ( $original_total - $total ), $original_total, $only_recurring, $this );

	}

	/**
	 * Get the registration total
	 *
	 * @param bool $discounts | Include discounts?
	 * @param bool $fees      | Include fees?
	 *
	 * @since 2.5
	 * @return float
	 */
	public function get_total( $discounts = true, $fees = true ) {

		$total = rcp_get_subscription_price( $this->subscription );
		// Used for tracking discount above total subscription price.
		$leftover = 0;
		if ( $fees ) {
			$total += $this->get_proration_credits();
		}

		if ( $discounts ) {
			$total -= $this->get_total_discounts( $total );
		}

		if ( 0 > $total ) {
			// Take the additional above the subscription and save it for later.
			$leftover = $total * -1;
			$total = 0;
		}

		if ( $fees ) {
			$total += $this->get_signup_fees( $total );
		}

		// If the total is above 0 and there's leftover, use it.
		if ( 0 < $total && 0 < $leftover ) {
			$total -= $leftover;
		}
		if ( 0 > $total ) {
			$total = 0;
		}

		return apply_filters( 'rcp_registration_get_total', floatval($total), $this );

	}

	/**
	 * Get the registration recurring total
	 *
	 * @param bool $discounts | Include discounts?
	 * @param bool $fees      | Include fees?
	 *
	 * @since 2.5
	 * @return float
	 */
	public function get_recurring_total( $discounts = true, $fees = true  ) {

		$total = rcp_get_subscription_price( $this->subscription );

		if ( $discounts ) {
			$total -= $this->get_total_discounts( $total, true );
		}

		if ( $fees ) {
			$total += $this->get_total_fees( $total, true );
		}

		if ( 0 > $total ) {
			$total = 0;
		}

		return apply_filters( 'rcp_registration_get_recurring_total', floatval( $total ), $this );

	}


}
