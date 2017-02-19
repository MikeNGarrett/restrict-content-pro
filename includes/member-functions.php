<?php
/**
 * Member Functions
 *
 * @package     Restrict Content Pro
 * @subpackage  Member Functions
 * @copyright   Copyright (c) 2017, Restrict Content Pro
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 */

/**
 * Returns an array of all members, based on subscription status
 *
 * @param string $status       The subscription status of users to retrieve
 * @param int    $subscription The subscription ID to retrieve users from
 * @param int    $offset       The number of users to skip, used for pagination
 * @param int    $number       The total users to retrieve, used for pagination
 * @param string $order        The order in which to display users: ASC / DESC
 * @param string $recurring    Retrieve recurring (or non recurring) only
 * @param string $search       Seach parameter
 *
 * @return array|bool          Array of users or false if none.
 */
function rcp_get_members( $status = 'active', $subscription = null, $offset = 0, $number = 999999, $order = 'DESC', $recurring = null, $search = '' ) {

	global $wpdb;

	$args = array(
		'offset' => $offset,
		'number' => $number,
		'count_total' => false,
		'orderby' => 'ID',
		'order' => $order,
		'meta_query' => array(
			array(
				'key' => 'rcp_status',
				'value' => $status
			)
		)
	);

	if( ! empty( $subscription ) ) {
		$args['meta_query'][] = array(
			'key'   => 'rcp_subscription_level',
			'value' => $subscription
		);
	}

	if( ! empty( $recurring ) ) {
		if( $recurring == 1 ) {
			// find non recurring users

			$args['meta_query'][] = array(
				'key'     => 'rcp_recurring',
				'compare' => 'NOT EXISTS'
			);
		} else {
			// find recurring users
			$args['meta_query'][] = array(
				'key'     => 'rcp_recurring',
				'value'   => 'yes'
			);
		}
	}

	if( ! empty( $search ) ) {
		$args['search'] = sanitize_text_field( $search );
	}

	$members = get_users( $args );

	if( !empty( $members ) )
		return $members;

	return false;
}

/**
 * Retrieves the total member counts for a status
 *
 * This retrieves the count for each subscription level and them sums the results.
 *
 * Use rcp_count_members() to retrieve a count based on level, status, recurring, and search terms.
 *
 * @access public
 * @since  2.6
 * @return int
 */
function rcp_get_member_count( $status = 'active' ) {

	global $rcp_levels_db;
	$levels = $rcp_levels_db->get_levels();

	if( ! $levels ) {
		return 0;
	}

	$total = 0;
	foreach( $levels as $level ) {

		$total += (int) rcp_get_subscription_member_count( $level->id, $status );

	}

	return $total;

}

/**
 * Counts the number of members by subscription level and status
 *
 * @param int    $level ID of the subscription level to count the members of.
 * @param string $status The status to count.
 * @param string $recurring    Retrieve recurring (or non recurring) only
 * @param string $search       Seach parameter
 *
 * @return int The number of members for the specified subscription level and status
 */
function rcp_count_members( $level = '', $status = 'active', $recurring = null, $search = '' ) {
	global $wpdb;

	if( $status == 'free' ) {

		if ( ! empty( $level ) ) :

			$args = array(
				'meta_query' => array(
					array(
						'key' => 'rcp_subscription_level',
						'value' => $level,
					),
					array(
						'key'   => 'rcp_status',
						'value' => 'free'
					)
				)
			);

		else :

			$args = array(
				'meta_query' => array(
					array(
						'key'   => 'rcp_status',
						'value' => 'free'
					)
				)
			);

		endif;

	} else {

		if ( ! empty( $level ) ) :

			$args = array(
				'meta_query' => array(
					array(
						'key'   => 'rcp_subscription_level',
						'value' =>  $level
					),
					array(
						'key'   => 'rcp_status',
						'value' => $status
					)
				)
			);

		else :

			$args = array(
				'meta_query' => array(
					array(
						'key'   => 'rcp_status',
						'value' => $status
					)
				)
			);

		endif;

	}

	if( ! empty( $recurring ) ) {
		if( $recurring == 1 ) {
			// find non recurring users

			$args['meta_query'][] = array(
				'key'     => 'rcp_recurring',
				'compare' => 'NOT EXISTS'
			);
		} else {
			// find recurring users
			$args['meta_query'][] = array(
				'key'     => 'rcp_recurring',
				'value'   => 'yes'
			);
		}
	}

	if( ! empty( $search ) ) {
		$args['search'] = sanitize_text_field( $search );
	}

	$args['fields'] = 'ID';
	$users = new WP_User_Query( $args );
	return $users->get_total();
}

/**
 * Retrieves the total number of members by subscription status
 *
 * @uses   rcp_count_members()
 *
 * @return array Array of all counts.
 */
function rcp_count_all_members() {
	$counts = array(
		'active' 	=> rcp_count_members('', 'active'),
		'pending' 	=> rcp_count_members('', 'pending'),
		'expired' 	=> rcp_count_members('', 'expired'),
		'cancelled' => rcp_count_members('', 'cancelled'),
		'free' 		=> rcp_count_members('', 'free')
	);
	return $counts;
}

/**
 * Gets all members of a particular subscription level
 *
 * @param int          $id     The ID of the subscription level to retrieve users for.
 * @param string|array $fields String or array of the user fields to retrieve.
 *
 * @return array An array of user objects
 */
function rcp_get_members_of_subscription( $id = 1, $fields = 'ID') {
	$members = get_users(array(
			'meta_key' 		=> 'rcp_subscription_level',
			'meta_value' 	=> $id,
			'number' 		=> 0,
			'fields' 		=> $fields,
			'count_total' 	=> false
		)
	);
	return $members;
}

/**
 * Gets a user's subscription level ID
 *
 * @param int $user_id The ID of the user to return the subscription level of, or 0 for current user.
 *
 * @return int|false The ID of the user's subscription level or false if none.
 */
function rcp_get_subscription_id( $user_id = 0 ) {

	if( empty( $user_id ) && is_user_logged_in() ) {
		$user_id = get_current_user_id();
	}

	$member = new RCP_Member( $user_id );
	return $member->get_subscription_id();

}

/**
 * Gets a user's subscription level name
 *
 * @param int $user_id The ID of the user to return the subscription level of, or 0 for current user.
 *
 * @return string The name of the user's subscription level
 */
function rcp_get_subscription( $user_id = 0 ) {

	if( empty( $user_id ) && is_user_logged_in() ) {
		$user_id = get_current_user_id();
	}

	$member = new RCP_Member( $user_id );
	return $member->get_subscription_name();

}


/**
 * Checks whether a user has a recurring subscription
 *
 * @param int $user_id The ID of the user to return the subscription level of, or 0 for current user.
 *
 * @return bool True if the user is recurring, false otherwise
 */
function rcp_is_recurring( $user_id = 0 ) {

	if( empty( $user_id ) && is_user_logged_in() ) {
		$user_id = get_current_user_id();
	}

	$member = new RCP_Member( $user_id );
	return $member->is_recurring();

}


/**
 * Checks whether a user is expired
 *
 * @param int $user_id The ID of the user to return the subscription level of, or 0 for current user.
 *
 * @return bool True if the user is expired, false otherwise
 */
function rcp_is_expired( $user_id = 0 ) {

	if( empty( $user_id ) && is_user_logged_in() ) {
		$user_id = get_current_user_id();
	}

	$member = new RCP_Member( $user_id );
	return $member->is_expired();

}

/**
 * Checks whether a user has an active subscription
 *
 * @param int $user_id The ID of the user to return the subscription level of, or 0 for current user.
 *
 * @return bool True if the user has an active, paid subscription (or is trialing), false otherwise
 */
function rcp_is_active( $user_id = 0 ) {

	if( empty( $user_id ) && is_user_logged_in() ) {
		$user_id = get_current_user_id();
	}

	$member = new RCP_Member( $user_id );
	return $member->is_active();

}

/**
 * Just a wrapper function for rcp_is_active()
 *
 * @param int $user_id - the ID of the user to return the subscription level of, or 0 for current user.
 *
 * @return bool True if the user has an active, paid subscription (or is trialing), false otherwise
 */
function rcp_is_paid_user( $user_id = 0) {

	$ret = false;

	if( empty( $user_id ) && is_user_logged_in() ) {
		$user_id = get_current_user_id();
	}

	if( rcp_is_active( $user_id ) ) {
		$ret = true;
	}
	return apply_filters( 'rcp_is_paid_user', $ret, $user_id );
}

/**
 * Checks if a user's subscription grants access to the provided access level.
 *
 * @param int $user_id             ID of the user to check, or 0 for the current user.
 * @param int $access_level_needed Access level needed.
 *
 * @return bool True if they have access, false if not.
 */
function rcp_user_has_access( $user_id = 0, $access_level_needed ) {

	$subscription_level = rcp_get_subscription_id( $user_id );
	$user_access_level = rcp_get_subscription_access_level( $subscription_level );

	if( ( $user_access_level >= $access_level_needed ) || $access_level_needed == 0 || current_user_can( 'manage_options' ) ) {
		// the user has access
		return true;
	}

	// the user does not have access
	return false;
}

/**
 * Wrapper function for RCP_Member->can_access()
 *
 * Returns true if user can access the current content
 *
 * @since  2.1
 * @return bool
 */
function rcp_user_can_access( $user_id = 0, $post_id = 0 ) {

	if( empty( $user_id ) ) {
		$user_id = get_current_user_id();
	}

	if( empty( $post_id ) ) {
		global $post;
		$post_id = $post->ID;
	}

	$member = new RCP_Member( $user_id );
	return $member->can_access( $post_id );
}

/**
 * Gets the date of a user's expiration in a nice format
 *
 * @param int $user_id The ID of the user to return the subscription level of, or 0 for the current user.
 *
 * @return string The date of the user's expiration, in the format specified in settings
 */
function rcp_get_expiration_date( $user_id = 0 ) {

	if( empty( $user_id ) ) {
		$user_id = get_current_user_id();
	}

	$member = new RCP_Member( $user_id );
	return $member->get_expiration_date( true, false );
}

/**
 * Sets the users expiration date
 *
 * @param int    $user_id The ID of the user to return the subscription level of, or 0 for the current user.
 * @param string $date    The expiration date in YYYY-MM-DD H:i:s
 *
 * @since 2.0
 * @return string The date of the user's expiration, in the format specified in settings
 */
function rcp_set_expiration_date( $user_id = 0, $new_date = '' ) {

	if( empty( $user_id ) ) {
		$user_id = get_current_user_id();
	}

	$member = new RCP_Member( $user_id );
	return $member->set_expiration_date( $new_date );
}

/**
 * Gets the date of a user's expiration in a unix time stamp
 *
 * @param int $user_id The ID of the user to return the subscription level of
 *
 * @return int|false Timestamp of expiration of false if no expiration
 */
function rcp_get_expiration_timestamp( $user_id ) {
	$expiration = get_user_meta( $user_id, 'rcp_expiration', true );
	return $expiration && $expiration !== 'none' ? strtotime( $expiration, current_time( 'timestamp' ) ) : false;
}

/**
 * Gets the status of a user's subscription. If a user is expired, this will update their status to "expired".
 *
 * @param int $user_id The ID of the user to return the subscription level of, or 0 for the current user.
 *
 * @return string The status of the user's subscription
 */
function rcp_get_status( $user_id = 0 ) {

	if( empty( $user_id ) ) {
		$user_id = get_current_user_id();
	}

	$member = new RCP_Member( $user_id );
	return $member->get_status();
}

/**
 * Gets a user's subscription status in a nice format that is localized
 *
 * @param int $user_id The ID of the user to return the subscription level of, or 0 for the current user.
 *
 * @return string The user's subscription status
 */
function rcp_print_status( $user_id = 0, $echo = true  ) {

	if( empty( $user_id ) ) {
		$user_id = get_current_user_id();
	}

	$status = rcp_get_status( $user_id );
	switch ( $status ) :

		case 'active';
			$print_status = __( 'Active', 'rcp' );
		break;
		case 'expired';
			$print_status = __( 'Expired', 'rcp' );
		break;
		case 'pending';
			$print_status = __( 'Pending', 'rcp' );
		break;
		case 'cancelled';
			$print_status = __( 'Cancelled', 'rcp' );
		break;
		default:
			$print_status = __( 'Free', 'rcp' );
		break;

	endswitch;

	if( $echo ) {
		echo $print_status;
	}

	return $print_status;
}

/**
 * Sets a user's status to the specified status
 *
 * @param int    $user_id    The ID of the user to return the subscription level of
 * @param string $new_status The status to set the user to
 *
 * @return bool True on a successful status change, false otherwise
 */
function rcp_set_status( $user_id = 0, $new_status = '' ) {

	if( empty( $user_id ) || empty( $new_status ) ) {
		return false;
	}

	$member = new RCP_Member( $user_id );
	return $member->set_status( $new_status );

}

/**
 * Gets the user's unique subscription key
 *
 * @param int $user_id The ID of the user to return the subscription level of, or 0 for the current user
 *
 * @return string/bool Key string if it is retrieved successfully, false on failure
 */
function rcp_get_subscription_key( $user_id = 0 ) {

	if( empty( $user_id ) ) {
		$user_id = get_current_user_id();
	}

	$member = new RCP_Member( $user_id );
	return $member->get_subscription_key();
}

/**
 * Checks whether a user has trialed
 *
 * @param int $user_id The ID of the user to return the subscription level of, or 0 for the current user
 *
 * @return bool True if the user has trialed, false otherwise
 */
function rcp_has_used_trial( $user_id = 0) {

	if( empty( $user_id ) ) {
		$user_id = get_current_user_id();
	}

	$member = new RCP_Member( $user_id );
	return $member->has_trialed();

}


/**
 * Checks if a user is currently trialing
 *
 * @param int $user_id ID of the user to check, or 0 for the current user.
 *
 * @access      public
 * @since       1.5
 * @return      bool
 */
function rcp_is_trialing( $user_id = 0 ) {

	if( empty( $user_id ) ) {
		$user_id = get_current_user_id();
	}

	$member = new RCP_Member( $user_id );
	return $member->is_trialing();

}


// prints payment history for the specified user
function rcp_print_user_payments( $user_id ) {
	$payments = new RCP_Payments;
	$user_payments = $payments->get_payments( array( 'user_id' => $user_id ) );
	$payments_list = '';
	if( $user_payments ) :
		foreach( $user_payments as $payment ) :
			$transaction_id = ! empty( $payment->transaction_id ) ? $payment->transaction_id : '';
			$payments_list .= '<ul class="rcp_payment_details">';
				$payments_list .= '<li>' . __( 'Date', 'rcp' ) . ': ' . $payment->date . '</li>';
				$payments_list .= '<li>' . __( 'Subscription', 'rcp' ) . ': ' . $payment->subscription . '</li>';
				$payments_list .= '<li>' . __( 'Payment Type', 'rcp' ) . ': ' . $payment->payment_type . '</li>';
				$payments_list .= '<li>' . __( 'Subscription Key', 'rcp' ) . ': ' . $payment->subscription_key . '</li>';
				$payments_list .= '<li>' . __( 'Transaction ID', 'rcp' ) . ': ' . $transaction_id . '</li>';
				if( $payment->amount != '' ) {
					$payments_list .= '<li>' . __( 'Amount', 'rcp' ) . ': ' . rcp_currency_filter( $payment->amount ) . '</li>';
				} else {
					$payments_list .= '<li>' . __( 'Amount', 'rcp' ) . ': ' . rcp_currency_filter( $payment->amount2 ) . '</li>';
				}
			$payments_list .= '</ul>';
		endforeach;
	else :
		$payments_list = '<p class="rcp-no-payments">' . __( 'No payments recorded', 'rcp' ) . '</p>';
	endif;
	return $payments_list;
}

/**
 * Prints payment history for the specific user in a formatted table
 *
 * @param int $user_id ID of the user to get the history for.
 *
 * @since  2.5
 * @return string
 */
function rcp_print_user_payments_formatted( $user_id ) {

	$payments = new RCP_Payments;
	$user_payments = $payments->get_payments( array( 'user_id' => $user_id ) );
	$payments_list = '';

	if ( ! $user_payments ) {
		return $payments_list;
	}

	ob_start();
	?>

	<table class="wp-list-table widefat fixed posts rcp-table rcp_payment_details" style="display: block; width: 100%;">

		<thead>
			<tr>
				<th><?php _e( 'ID', 'rcp' ); ?></th>
				<th><?php _e( 'Date', 'rcp' ); ?></th>
				<th><?php _e( 'Subscription', 'rcp' ); ?></th>
				<th><?php _e( 'Payment Type', 'rcp' ); ?></th>
				<th><?php _e( 'Subscription Key', 'rcp' ); ?></th>
				<th><?php _e( 'Transaction ID', 'rcp' ); ?></th>
				<th><?php _e( 'Amount', 'rcp' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach( $user_payments as $payment ) : ?>

				<tr>
					<td><a href="<?php echo esc_url( add_query_arg( array( 'payment_id' => $payment->id, 'view' => 'edit-payment' ), admin_url( 'admin.php?page=rcp-payments' ) ) ); ?>" class="rcp-edit-payment"><?php echo esc_html( $payment->id ); ?></a></td>
					<td><?php echo esc_html( $payment->date ); ?></td>
					<td><?php echo esc_html( $payment->subscription ); ?></td>
					<td><?php echo esc_html( $payment->payment_type ); ?></td>
					<td><?php echo esc_html( $payment->subscription_key ); ?></td>
					<td><?php echo rcp_get_merchant_transaction_id_link( $payment ); ?></td>
					<td><?php echo ( '' == $payment->amount ) ? esc_html( rcp_currency_filter( $payment->amount2 ) ) : esc_html( rcp_currency_filter( $payment->amount ) ); ?></td>
				</tr>

			<?php endforeach; ?>
		</tbody>

	</table>

	<?php
	return apply_filters( 'rcp_print_user_payments_formatted', ob_get_clean(), $user_id );
}

/**
 * Retrieve the payments for a specific user
 *
 * @param int $user_id The ID of the user to get payments for
 *
 * @since  1.5
 * @return array
*/
function rcp_get_user_payments( $user_id = 0 ) {

	if( empty( $user_id ) ) {
		$user_id = get_current_user_id();
	}

	$payments = new RCP_Payments;
	return $payments->get_payments( array( 'user_id' => $user_id ) );
}

/**
 * Returns the role of the specified user
 *
 * @param int $user_id The ID of the user to get the role of
 *
 * @return int|string
 */
function rcp_get_user_role( $user_id ) {

	global $wpdb;

	$user = new WP_User( $user_id );
	$capabilities = $user->{$wpdb->prefix . 'capabilities'};

	if ( !isset( $wp_roles ) ) {
		$wp_roles = new WP_Roles();
	}

	$user_role = '';

	if( ! empty( $capabilities ) ) {
		foreach ( $wp_roles->role_names as $role => $name ) {

			if ( array_key_exists( $role, $capabilities ) ) {
				$user_role = $role;
			}
		}
	}

	return $user_role;
}

/**
 * Inserts a new note for a user
 *
 * @param int    $user_id ID of the user to add a note to.
 * @param string $note    Note to add.
 *
 * @since  2.0
 * @return void
 */
function rcp_add_member_note( $user_id = 0, $note = '' ) {
	$notes = get_user_meta( $user_id, 'rcp_notes', true );
	if( ! $notes ) {
		$notes = '';
	}
	$notes .= "\n\n" . date_i18n( 'F j, Y H:i:s', current_time( 'timestamp' ) ) . ' - ' . $note;

	update_user_meta( $user_id, 'rcp_notes', wp_kses( $notes, array() ) );
}


/**
 * Determine if it's possible to upgrade a user's subscription
 *
 * @param int $user_id The ID of the user to check, or 0 for the current user.
 *
 * @since  1.5
 * @return bool
*/

function rcp_subscription_upgrade_possible( $user_id = 0 ) {

	if( empty( $user_id ) )
		$user_id = get_current_user_id();

	$ret = false;

	if( ( ! rcp_is_active( $user_id ) || ! rcp_is_recurring( $user_id ) ) && rcp_has_paid_levels() )
		$ret = true;

	if ( rcp_has_upgrade_path( $user_id ) ) {
		$ret = true;
	}

	return (bool) apply_filters( 'rcp_can_upgrade_subscription', $ret, $user_id );
}

/**
 * Does this user have an upgrade path?
 *
 * @uses  rcp_get_upgrade_paths()
 *
 * @param int $user_id the ID of the user to check, or 0 for the current user.
 *
 * @since  2.5
 * @return bool True if an upgrade path is available, false if not.
 */
function rcp_has_upgrade_path( $user_id = 0 ) {
	return apply_filters( 'rcp_has_upgrade_path', ( bool ) rcp_get_upgrade_paths( $user_id ), $user_id );
}

/**
 * Get subscriptions to which this user can upgrade
 *
 * @param int $user_id the ID of the user to check, or 0 for the current user.
 *
 * @since 2.5
 * @return array Array of subscriptions.
 */
function rcp_get_upgrade_paths( $user_id = 0 ) {

	if ( empty( $user_id ) ) {
		$user_id = get_current_user_id();
	}

	// make sure the user is active and get the subscription ID
	$user_subscription = ( rcp_is_recurring( $user_id ) && rcp_is_active( $user_id ) && 'cancelled' !== rcp_get_status() ) ? rcp_get_subscription_id( $user_id ) : '';
	$subscriptions     = rcp_get_subscription_levels( 'active' );

	// remove the user's current subscription from the list
	foreach( $subscriptions as $key => $subscription ) {
		if ( $user_subscription == $subscription->id ) {
			unset( $subscriptions[ $key ] );
		}
	}

	return apply_filters( 'rcp_get_upgrade_paths', array_values( $subscriptions ), $user_id );
}

/**
 * Process Profile Updater Form
 *
 * Processes the profile updater form by updating the necessary fields
 *
 * @access  private
 * @since   1.5
 * @return  void
*/
function rcp_process_profile_editor_updates() {

	// Profile field change request
	if ( empty( $_POST['rcp_action'] ) || $_POST['rcp_action'] !== 'edit_user_profile' || !is_user_logged_in() )
		return false;


	// Nonce security
	if ( ! wp_verify_nonce( $_POST['rcp_profile_editor_nonce'], 'rcp-profile-editor-nonce' ) )
		return false;

	$user_id      = get_current_user_id();
	$old_data     = get_userdata( $user_id );

	$display_name = ! empty( $_POST['rcp_display_name'] ) ? sanitize_text_field( $_POST['rcp_display_name'] ) : '';
	$first_name   = ! empty( $_POST['rcp_first_name'] )   ? sanitize_text_field( $_POST['rcp_first_name'] )   : '';
	$last_name    = ! empty( $_POST['rcp_last_name'] )    ? sanitize_text_field( $_POST['rcp_last_name'] )    : '';
	$email        = ! empty( $_POST['rcp_email'] )        ? sanitize_text_field( $_POST['rcp_email'] )        : '';

	$userdata = array(
		'ID'           => $user_id,
		'first_name'   => $first_name,
		'last_name'    => $last_name,
		'display_name' => $display_name,
		'user_email'   => $email
	);

	// Empty email
	if ( empty( $email ) || ! is_email( $email ) ) {
		rcp_errors()->add( 'empty_email', __( 'Please enter a valid email address', 'rcp' ) );
	}

	// Make sure the new email doesn't belong to another user
	if( $email != $old_data->user_email && email_exists( $email ) ) {
		rcp_errors()->add( 'email_exists', __( 'The email you entered belongs to another user. Please use another.', 'rcp' ) );
	}

	// New password
	if ( ! empty( $_POST['rcp_new_user_pass1'] ) ) {
		if ( $_POST['rcp_new_user_pass1'] !== $_POST['rcp_new_user_pass2'] ) {
			rcp_errors()->add( 'password_mismatch', __( 'The passwords you entered do not match. Please try again.', 'rcp' ) );
		} else {
			$userdata['user_pass'] = $_POST['rcp_new_user_pass1'];
		}
	}

	do_action( 'rcp_edit_profile_form_errors', $_POST, $user_id );

	// retrieve all error messages, if any
	$errors = rcp_errors()->get_error_messages();

	// only create the user if there are no errors
	if( empty( $errors ) ) {

		// Update the user
		$updated = wp_update_user( $userdata );
		$updated = apply_filters( 'rcp_edit_profile_update_user', $updated, $user_id, $_POST );

		if( $updated ) {
			do_action( 'rcp_user_profile_updated', $user_id, $userdata, $old_data );

			wp_safe_redirect( add_query_arg( 'updated', 'true', sanitize_text_field( $_POST['rcp_redirect'] ) ) );

			exit;
		} else {
			rcp_errors()->add( 'not_updated', __( 'There was an error updating your profile. Please try again.', 'rcp' ) );
		}
	}
}
add_action( 'init', 'rcp_process_profile_editor_updates' );

/**
 * Change a user password
 *
 * @access  public
 * @since   1.0
 * @return  void
 */
function rcp_change_password() {
	// reset a users password
	if( isset( $_POST['rcp_action'] ) && $_POST['rcp_action'] == 'reset-password' ) {

		global $user_ID;

		list( $rp_path ) = explode( '?', wp_unslash( $_SERVER['REQUEST_URI'] ) );
		$rp_cookie = apply_filters( 'rcp_resetpass_cookie_name', 'rcp-resetpass-' . COOKIEHASH );

		$user = rcp_get_user_resetting_password( $rp_cookie );

		if( !is_user_logged_in() && !$user) {
			return;
		}

		if( wp_verify_nonce( $_POST['rcp_password_nonce'], 'rcp-password-nonce' ) ) {

			do_action( 'rcp_before_password_form_errors', $_POST );

			if( $_POST['rcp_user_pass'] == '' || $_POST['rcp_user_pass_confirm'] == '' ) {
				// password(s) field empty
				rcp_errors()->add( 'password_empty', __( 'Please enter a password, and confirm it', 'rcp' ), 'password' );
			}
			if( $_POST['rcp_user_pass'] != $_POST['rcp_user_pass_confirm'] ) {
				// passwords do not match
				rcp_errors()->add( 'password_mismatch', __( 'Passwords do not match', 'rcp' ), 'password' );
			}

			do_action( 'rcp_password_form_errors', $_POST );

			// retrieve all error messages, if any
			$errors = rcp_errors()->get_error_messages();

			if( empty( $errors ) ) {
				// change the password here
				$user_data = array(
					'ID' 		=> (is_user_logged_in()) ? $user_ID : $user->ID,
					'user_pass' => $_POST['rcp_user_pass']
				);
				wp_update_user( $user_data );
				// remove cookie with password reset info
				setcookie( $rp_cookie, ' ', time() - YEAR_IN_SECONDS, $rp_path, COOKIE_DOMAIN, is_ssl(), true );
				// send password change email here (if WP doesn't)
				wp_safe_redirect( add_query_arg( 'password-reset', 'true', $_POST['rcp_redirect'] ) );
				exit;
			}
		}
	}
}
add_action( 'init', 'rcp_change_password' );

/**
 * Process a member cancellation request
 *
 * @access  public
 * @since   2.1
 * @return  void
 */
function rcp_process_member_cancellation() {

	if( ! isset( $_GET['rcp-action'] ) || $_GET['rcp-action'] !== 'cancel' ) {
		return;
	}

	if( ! is_user_logged_in() ) {
		return;
	}

	if( wp_verify_nonce( $_GET['_wpnonce'], 'rcp-cancel-nonce' ) ) {

		global $rcp_options;

		$success  = rcp_cancel_member_payment_profile( get_current_user_id() );
		$redirect = remove_query_arg( array( 'rcp-action', '_wpnonce', 'member-id' ), rcp_get_current_url() );

		if( ! $success && rcp_is_paypal_subscriber() ) {
			// No profile ID stored, so redirect to PayPal to cancel manually
			$redirect = 'https://www.paypal.com/cgi-bin/customerprofileweb?cmd=_manage-paylist';
		}

		if( $success ) {

			do_action( 'rcp_process_member_cancellation', get_current_user_id() );

			$redirect = add_query_arg( 'profile', 'cancelled', $redirect );

		}

		wp_redirect( $redirect ); exit;

	}
}
add_action( 'template_redirect', 'rcp_process_member_cancellation' );

/**
 * Cancel a member's payment profile
 *
 * @param int  $member_id  ID of the member to cancel.
 * @param bool $set_status Whether or not to update the status to 'cancelled'.
 *
 * @access  public
 * @since   2.1
 * @return  bool Whether or not the cancellation was successful.
 */
function rcp_cancel_member_payment_profile( $member_id = 0, $set_status = true ) {

	$member = new RCP_Member( $member_id );

	return $member->cancel_payment_profile( $set_status );
}

/**
 * Updates member payment profile ID meta keys with old versions from pre 2.1 gateways
 *
 * @param string     $profile_id
 * @param int        $user_id
 * @param RCP_Member $member_object
 *
 * @access  public
 * @since   2.1
 * @return  string   The profile ID.
 */
function rcp_backfill_payment_profile_ids( $profile_id, $user_id, $member_object ) {

	if( empty( $profile_id ) ) {

		// Check for Stripe
		$profile_id = get_user_meta( $user_id, '_rcp_stripe_user_id', true );

		if( ! empty( $profile_id ) ) {

			$member_object->set_payment_profile_id( $profile_id );

		} else {

			// Check for PayPal
			$profile_id = get_user_meta( $user_id, 'rcp_recurring_payment_id', true );

			if( ! empty( $profile_id ) ) {

				$member_object->set_payment_profile_id( $profile_id );

			}

		}

	}

	return $profile_id;
}
add_filter( 'rcp_member_get_payment_profile_id', 'rcp_backfill_payment_profile_ids', 10, 3 );

/**
 * Retrieves the member's ID from their payment profile ID
 *
 * @param   string $profile_id Profile ID.
 *
 * @access  public
 * @since   2.1
 * @return  int|false User ID if found, false if not.
 */
function rcp_get_member_id_from_profile_id( $profile_id = '' ) {

	global $wpdb;

	$user_id = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM $wpdb->usermeta WHERE meta_key = 'rcp_payment_profile_id' AND meta_value = %s LIMIT 1", $profile_id ) );

	if ( $user_id != NULL ) {
		return $user_id;
	}

	return false;
}

/**
 * Determines if a member can renew their subscription
 *
 * @param int $user_id The user ID to check, or 0 for the current user.
 *
 * @access public
 * @since  2.3
 * @return bool True if the user can renew, false if not.
 */
function rcp_can_member_renew( $user_id = 0 ) {

	if( empty( $user_id ) ) {
		$user_id = get_current_user_id();
	}

	$ret    = true;
	$member = new RCP_Member( $user_id );

	if( $member->is_recurring() && $member->is_active() && 'cancelled' !== $member->get_status() ) {
		$ret = false;

	}

	if( 'free' == $member->get_status() ) {

		$ret = false;

	}

	if( ! rcp_subscription_upgrade_possible( $user_id ) ) {

		$ret = false;

	}

	return apply_filters( 'rcp_member_can_renew', $ret, $user_id );
}

/**
 * Determines if a member can cancel their subscription on site
 *
 * @param int $user_id The user ID to check, or 0 for the current user.
 *
 * @access  public
 * @since   2.1
 * @return  bool True if the member can cancel, false if not.
 */
function rcp_can_member_cancel( $user_id = 0 ) {

	if( empty( $user_id ) ) {
		$user_id = get_current_user_id();
	}

	$member = new RCP_Member( $user_id );

	return $member->can_cancel();
}

/**
 * Gets the cancellation URL for a member
 *
 * @param int $user_id The user ID to get the link for, or 0 for the current user.
 *
 * @access  public
 * @since   2.1
 * @return  string Cancellation URL.
 */
function rcp_get_member_cancel_url( $user_id = 0 ) {

	if( empty( $user_id ) ) {
		$user_id = get_current_user_id();
	}

	$url    = '';
	$member = new RCP_Member( $user_id );

	if( $member->is_recurring() ) {

		$url = wp_nonce_url( add_query_arg( array( 'rcp-action' => 'cancel', 'member-id' => $user_id ) ), 'rcp-cancel-nonce' );

	}

	return apply_filters( 'rcp_member_cancel_url', $url, $user_id );
}

/**
 * Determines if a member can update the credit / debit card attached to their account
 *
 * @param int $user_id The ID of the user to check, or 0 for the current user.
 *
 * @access  public
 * @since   2.1
 * @return  bool
 */
function rcp_member_can_update_billing_card( $user_id = 0 ) {

	global $rcp_options;

	if( empty( $user_id ) ) {
		$user_id = get_current_user_id();
	}

	$ret = false;

	// Check if the member is a Stripe customer
	if( rcp_is_stripe_subscriber( $user_id ) ) {

		$ret = true;

	} elseif ( rcp_is_paypal_subscriber( $user_id ) && rcp_has_paypal_api_access() ) {

		$ret = true;

	} elseif ( rcp_is_authnet_subscriber( $user_id ) && rcp_has_authnet_api_access() ) {

		$ret = true;

	}

	return apply_filters( 'rcp_member_can_update_billing_card', $ret, $user_id );
}

/**
 * Wrapper for RCP_Member->get_switch_to_url()
 *
 * @param int $user_id ID of the user to get the switch to URL for.
 *
 * @access public
 * @since  2.1
 * @return string|false The URL if available, false if not.
 */
function rcp_get_switch_to_url( $user_id = 0 ) {

	if( empty( $user_id ) ) {
		return;
	}

	$member = new RCP_Member( $user_id );
	return $member->get_switch_to_url();

}

/**
 * Validate a potential username
 *
 * @param       string $username The username to validate
 *
 * @access      public
 * @since       2.2
 * @return      bool
 */
function rcp_validate_username( $username = '' ) {
	$sanitized = sanitize_user( $username, false );
	$valid = ( $sanitized == $username );
	return (bool) apply_filters( 'rcp_validate_username', $valid, $username );
}

/**
 * Get the prorate amount for this member
 *
 * @param int $user_id
 *
 * @since 2.5
 * @return int
 */
function rcp_get_member_prorate_credit( $user_id = 0 ) {
	if( empty( $user_id ) ) {
		$user_id = get_current_user_id();
	}

	$member = new RCP_Member( $user_id );

	return $member->get_prorate_credit_amount();
}

/**
 * Disable toolbar for non-admins if option is enabled
 *
 * @since 2.7
 *
 * @return void
 */
function rcp_maybe_disable_toolbar() {

	global $rcp_options;

	if ( isset( $rcp_options['disable_toolbar'] ) && ! current_user_can( 'manage_options' ) ) {
		add_filter( 'show_admin_bar', '__return_false' );
		// Hide BuddyPress admin bar.
		remove_action( 'init', 'bp_core_load_admin_bar', 9 );
	}
}
add_action( 'init', 'rcp_maybe_disable_toolbar', 9999 );

/**
 * Removes the subscription-assigned role from a member when the member expires.
 *
 * @param string     $status     Status that was just set.
 * @param int        $member_id  ID of the member.
 * @param string     $old_status Previous status.
 * @param RCP_Member $member     Member object.
 *
 * @since  2.7
 * @return void
 */
function rcp_update_expired_member_role( $status, $member_id, $old_status, $member ) {

	if ( 'expired' !== $status ) {
		return;
	}

	$subscription = rcp_get_subscription_details( $member->get_subscription_id() );

	$default_role = get_option( 'default_role', 'subscriber' );

	if ( ! empty( $subscription ) && is_object( $subscription ) && $subscription->role !== $default_role ) {
		$member->remove_role( $subscription->role );
	}
}
add_action( 'rcp_set_status', 'rcp_update_expired_member_role', 10, 4 );
