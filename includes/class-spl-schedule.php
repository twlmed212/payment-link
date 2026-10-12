<?php
/**
 * The payment link's life cycle on an order: sending it, the reminder schedule,
 * stopping, and cancelling the order when it expires unpaid.
 *
 * The clock starts when the link is first sent. Each step runs as one
 * WooCommerce scheduled action (Tools → Scheduled Actions, group
 * "syssell-payment-link"); every run plans the next one. Leaving On hold —
 * marked paid, cancelled, refunded — removes whatever is still planned.
 *
 * @package SysSell_Payment_Link
 */

defined( 'ABSPATH' ) || exit;

/**
 * Payment link state and scheduling.
 */
class SPL_Schedule {

	const HOOK  = 'syssell_payment_link_tick';
	const GROUP = 'syssell-payment-link';

	// Order meta (read and written through WC_Order, so it works with HPOS).
	const META_LINK      = '_spl_link';           // The payment link.
	const META_STARTED   = '_spl_started';        // When the link was first sent (the clock).
	const META_REMINDERS = '_spl_reminders_sent'; // Reminders sent so far.
	const META_LAST_SENT = '_spl_last_sent';      // When the customer was last emailed.
	const META_STOPPED   = '_spl_stopped';        // When an admin stopped the reminders.
	const META_EXPIRED   = '_spl_expired';        // When the order was cancelled for non-payment.

	/**
	 * Hook in.
	 */
	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'tick' ) );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'status_changed' ), 10, 3 );
		add_action( 'init', array( __CLASS__, 'maybe_reschedule_all' ) );
	}

	/** Whether the order was placed with this payment method. */
	public static function is_ours( $order ): bool {
		return $order instanceof WC_Order && SPL_Gateway::ID === $order->get_payment_method();
	}

	/** The order's payment link, or ''. */
	public static function link( WC_Order $order ): string {
		return (string) $order->get_meta( self::META_LINK );
	}

	/**
	 * Reminder days and the cancel day, from the gateway settings.
	 *
	 * @return array{reminders:int[],cancel:int} Days after the link was sent; cancel 0 = never.
	 */
	public static function plan(): array {
		$cancel = max( 0, (int) SPL_Gateway::setting( 'cancel_after' ) );
		$days   = array_map( 'intval', preg_split( '/[^0-9]+/', SPL_Gateway::setting( 'reminder_days' ), -1, PREG_SPLIT_NO_EMPTY ) );
		$days   = array_values(
			array_unique(
				array_filter(
					$days,
					static function ( $day ) use ( $cancel ) {
						return $day > 0 && ( 0 === $cancel || $day < $cancel );
					}
				)
			)
		);
		sort( $days );
		return array(
			'reminders' => $days,
			'cancel'    => $cancel,
		);
	}

	/** When the clock started (link first sent), or 0. */
	public static function started( WC_Order $order ): int {
		return (int) $order->get_meta( self::META_STARTED );
	}

	/** When an unpaid order will be cancelled, or 0 (not started / never). */
	public static function cancel_time( WC_Order $order ): int {
		$plan    = self::plan();
		$started = self::started( $order );
		return ( $started && $plan['cancel'] ) ? $started + $plan['cancel'] * DAY_IN_SECONDS : 0;
	}

	/** The cancel date in the store's date format, or ''. */
	public static function cancel_date( WC_Order $order ): string {
		$time = self::cancel_time( $order );
		return $time ? wp_date( get_option( 'date_format' ), $time ) : '';
	}

	/** When the next step is planned, or 0. */
	public static function next_run( WC_Order $order ): int {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return 0;
		}
		$next = as_next_scheduled_action( self::HOOK, self::args( $order ), self::GROUP );
		return is_int( $next ) ? $next : 0;
	}

	/** Whether reminders are running for this order. */
	public static function is_active( WC_Order $order ): bool {
		return $order->has_status( 'on-hold' ) && '' !== self::link( $order ) && ! $order->get_meta( self::META_STOPPED ) && self::next_run( $order ) > 0;
	}

	/** Scheduled-action arguments for an order. */
	private static function args( WC_Order $order ): array {
		return array( 'order_id' => $order->get_id() );
	}

	/* ------------------------------------------------------------------
	 * Actions (called from the order screen)
	 * ---------------------------------------------------------------- */

	/**
	 * Save a link. On an On hold order it is emailed at once: the first link
	 * starts the reminder clock; a changed link is re-sent and the reminders
	 * that follow use it (the clock doesn't restart).
	 *
	 * @param WC_Order $order Order.
	 * @param string   $link  Validated https URL.
	 * @return bool Whether the customer was emailed.
	 */
	public static function save_link( WC_Order $order, string $link ): bool {
		$first = '' === self::link( $order ) || ! self::started( $order );
		$order->update_meta_data( self::META_LINK, $link );
		$order->save();

		if ( ! $order->has_status( 'on-hold' ) ) {
			$order->add_order_note( __( 'Payment link saved. It was not emailed because the order is not On hold.', 'syssell-payment-link' ) );
			return false;
		}
		if ( $first ) {
			return self::start( $order );
		}
		$sent = self::send( $order, 'updated' );
		if ( $sent ) {
			$order->add_order_note( __( 'Payment link changed and emailed to the customer. Later reminders use the new link.', 'syssell-payment-link' ) );
		}
		return $sent;
	}

	/**
	 * Email the link and start (or restart) the clock and the reminders.
	 *
	 * @param WC_Order $order Order.
	 * @return bool Whether the customer was emailed.
	 */
	public static function start( WC_Order $order ): bool {
		$order->update_meta_data( self::META_STARTED, time() );
		$order->update_meta_data( self::META_REMINDERS, 0 );
		$order->delete_meta_data( self::META_STOPPED );
		$order->save();

		$sent = self::send( $order, 'initial' );
		if ( ! $sent ) {
			// Nothing reached the customer, so there is nothing to remind them of.
			$order->delete_meta_data( self::META_STARTED );
			$order->save();
			return false;
		}

		self::schedule_next( $order );
		$cancel = self::cancel_date( $order );
		$order->add_order_note(
			$cancel
				/* translators: 1: customer email, 2: date */
				? sprintf( __( 'Payment link emailed to %1$s. Reminders are scheduled; if it is still unpaid, the order will be cancelled on %2$s.', 'syssell-payment-link' ), $order->get_billing_email(), $cancel )
				/* translators: %s: customer email */
				: sprintf( __( 'Payment link emailed to %s. Reminders are scheduled.', 'syssell-payment-link' ), $order->get_billing_email() )
		);
		return true;
	}

	/**
	 * Email the current link again now. The schedule is unchanged.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public static function resend( WC_Order $order ): bool {
		$sent = self::send( $order, 'initial' );
		if ( $sent ) {
			/* translators: %s: customer email */
			$order->add_order_note( sprintf( __( 'Payment link emailed again to %s.', 'syssell-payment-link' ), $order->get_billing_email() ) );
		}
		return $sent;
	}

	/**
	 * Stop reminders and the automatic cancel for this order.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function stop( WC_Order $order ): void {
		self::unschedule( $order );
		$order->update_meta_data( self::META_STOPPED, time() );
		$order->save();
		$user = wp_get_current_user();
		$order->add_order_note(
			$user->exists()
				/* translators: %s: admin name */
				? sprintf( __( 'Payment link reminders stopped by %s. The order will not be cancelled automatically.', 'syssell-payment-link' ), $user->display_name )
				: __( 'Payment link reminders stopped. The order will not be cancelled automatically.', 'syssell-payment-link' )
		);
	}

	/**
	 * Remove the link (and with it the reminders).
	 *
	 * @param WC_Order $order Order.
	 */
	public static function remove_link( WC_Order $order ): void {
		self::unschedule( $order );
		foreach ( array( self::META_LINK, self::META_STARTED, self::META_REMINDERS, self::META_STOPPED ) as $key ) {
			$order->delete_meta_data( $key );
		}
		$order->save();
		$order->add_order_note( __( 'Payment link removed. No more reminders will be sent.', 'syssell-payment-link' ) );
	}

	/* ------------------------------------------------------------------
	 * Schedule
	 * ---------------------------------------------------------------- */

	/**
	 * Plan the next step (a reminder, or the cancel) for an order whose
	 * reminders are running. Replaces anything already planned.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function schedule_next( WC_Order $order ): void {
		self::unschedule( $order );
		if ( ! function_exists( 'as_schedule_single_action' ) || ! $order->has_status( 'on-hold' ) || '' === self::link( $order ) || $order->get_meta( self::META_STOPPED ) ) {
			return;
		}
		$due = self::next_due( $order );
		if ( $due ) {
			// A step that is already overdue (the site was offline, the plugin was off) runs shortly.
			as_schedule_single_action( max( $due, time() + MINUTE_IN_SECONDS ), self::HOOK, self::args( $order ), self::GROUP );
		}
	}

	/**
	 * When the order's next step is due, or 0 when nothing is left.
	 *
	 * @param WC_Order $order Order.
	 * @return int
	 */
	private static function next_due( WC_Order $order ): int {
		$started = self::started( $order );
		if ( ! $started ) {
			return 0;
		}
		$plan = self::plan();
		$sent = (int) $order->get_meta( self::META_REMINDERS );
		if ( isset( $plan['reminders'][ $sent ] ) ) {
			return $started + $plan['reminders'][ $sent ] * DAY_IN_SECONDS;
		}
		return self::cancel_time( $order );
	}

	/** Remove what is planned for an order. */
	public static function unschedule( WC_Order $order ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, self::args( $order ), self::GROUP );
		}
	}

	/**
	 * A planned step runs: send the next reminder, or cancel the unpaid order.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function tick( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! self::is_ours( $order ) || ! $order->has_status( 'on-hold' ) || '' === self::link( $order ) || $order->get_meta( self::META_STOPPED ) ) {
			return;
		}

		$due = self::next_due( $order );
		if ( ! $due ) {
			return;
		}
		// Ran early (e.g. a duplicate left over from a settings change): plan it for the right time instead.
		if ( $due > time() + MINUTE_IN_SECONDS ) {
			self::schedule_next( $order );
			return;
		}

		$plan = self::plan();
		$sent = (int) $order->get_meta( self::META_REMINDERS );

		if ( isset( $plan['reminders'][ $sent ] ) ) {
			$number  = $sent + 1;
			$total   = count( $plan['reminders'] );
			$is_last = $number === $total && $plan['cancel'] > 0;
			$ok      = self::send( $order, $is_last ? 'final' : 'reminder' );
			// Counted even if the email failed, so a mail outage can't stall the schedule.
			$order->update_meta_data( self::META_REMINDERS, $number );
			$order->save();
			$order->add_order_note(
				$ok
					/* translators: 1: reminder number, 2: number of reminders */
					? sprintf( __( 'Payment link reminder %1$d of %2$d emailed to the customer.', 'syssell-payment-link' ), $number, $total )
					/* translators: 1: reminder number, 2: number of reminders */
					: sprintf( __( 'Payment link reminder %1$d of %2$d could not be emailed.', 'syssell-payment-link' ), $number, $total )
			);
			self::schedule_next( $order );
			return;
		}

		if ( $plan['cancel'] > 0 ) {
			self::expire( $order, $plan['cancel'] );
		}
	}

	/**
	 * Cancel an order that stayed unpaid (stock goes back) and tell the customer.
	 *
	 * @param WC_Order $order Order.
	 * @param int      $days  Days it waited.
	 */
	private static function expire( WC_Order $order, int $days ): void {
		$order->update_meta_data( self::META_EXPIRED, time() );
		/* translators: %d: number of days */
		$order->update_status( 'cancelled', sprintf( __( 'No payment received within %d days of sending the payment link, so the order was cancelled automatically.', 'syssell-payment-link' ), $days ) );

		$mailer = WC()->mailer();
		$emails = $mailer->get_emails();
		if ( isset( $emails['SPL_Email_Link_Expired'] ) && $emails['SPL_Email_Link_Expired']->trigger( $order->get_id() ) ) {
			$order->add_order_note( __( 'Customer emailed that the order was cancelled.', 'syssell-payment-link' ) );
		}
	}

	/**
	 * Email the link to the customer.
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $variant initial | updated | reminder | final.
	 * @return bool Whether it was sent.
	 */
	public static function send( WC_Order $order, string $variant ): bool {
		$emails = WC()->mailer()->get_emails();
		if ( empty( $emails['SPL_Email_Payment_Link'] ) ) {
			return false;
		}
		$email = $emails['SPL_Email_Payment_Link'];
		if ( ! $email->is_enabled() ) {
			$order->add_order_note( __( 'The invoice was not emailed: the “Card invoice” email is turned off in WooCommerce → Settings → Emails.', 'syssell-payment-link' ) );
			return false;
		}
		$sent = $email->trigger( $order->get_id(), $variant );
		if ( $sent ) {
			$order->update_meta_data( self::META_LAST_SENT, time() );
			$order->save();
		} elseif ( 'reminder' !== $variant && 'final' !== $variant ) {
			$order->add_order_note( __( 'The payment link email could not be sent. Check the store’s email delivery, then use “Resend now”.', 'syssell-payment-link' ) );
		}
		return $sent;
	}

	/* ------------------------------------------------------------------
	 * Hooks
	 * ---------------------------------------------------------------- */

	/**
	 * Leaving On hold (paid, cancelled, refunded …) ends the reminders.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $from     Old status.
	 * @param string $to       New status.
	 */
	public static function status_changed( $order_id, $from, $to ): void {
		if ( 'on-hold' !== $from || 'on-hold' === $to ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( self::is_ours( $order ) ) {
			self::unschedule( $order );
		}
	}

	/**
	 * After the plugin is turned back on, re-plan the reminders of every order
	 * still waiting (deactivation removed them).
	 */
	public static function maybe_reschedule_all(): void {
		if ( 'yes' !== get_option( 'spl_reschedule_pending' ) || ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		delete_option( 'spl_reschedule_pending' );
		$orders = wc_get_orders(
			array(
				'status'         => 'on-hold',
				'payment_method' => SPL_Gateway::ID,
				'limit'          => -1,
			)
		);
		foreach ( $orders as $order ) {
			if ( self::started( $order ) ) {
				self::schedule_next( $order );
			}
		}
	}
}
