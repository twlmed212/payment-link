<?php
/**
 * Admin: the "Card payment link" box on the order screen and the "Payment
 * link" column in the orders list.
 *
 * The box sits inside WooCommerce's order form, so its buttons save the order
 * like "Update" does; the link is handled after WooCommerce has saved the
 * status (woocommerce_process_shop_order_meta, priority 60). Pressing Enter in
 * the link field saves it too.
 *
 * @package SysSell_Payment_Link
 */

defined( 'ABSPATH' ) || exit;

/**
 * Order screen and orders list.
 */
class SPL_Admin {

	/**
	 * Hook in.
	 */
	public static function init(): void {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ), 40 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'place_meta_box' ), 99 );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save' ), 60, 1 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );

		// Orders list: order tables (HPOS) and the older posts screen.
		add_filter( 'woocommerce_shop_order_list_table_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'woocommerce_shop_order_list_table_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_action( 'admin_head', array( __CLASS__, 'styles' ) );
	}

	/**
	 * The box: on orders paid with this method, and on unpaid orders placed
	 * with another one (a declined card, an abandoned crypto or card payment …)
	 * so an invoice can be sent to them too.
	 *
	 * @param string $screen_id Screen ID.
	 */
	public static function add_meta_box( $screen_id ): void {
		$order_screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		if ( $screen_id !== $order_screen ) {
			return;
		}
		$order = self::current_order();
		if ( ! SPL_Schedule::is_ours( $order ) && ! self::can_switch( $order ) ) {
			return;
		}
		add_meta_box( 'spl-payment-link', __( 'Card payment link', 'syssell-payment-link' ), array( __CLASS__, 'render_box' ), $order_screen, 'side', 'high' );
	}

	/**
	 * Put the box right under "Order actions", above WooCommerce's attribution
	 * and customer-history boxes (until the admin drags it elsewhere).
	 *
	 * @param string $screen_id Screen ID.
	 */
	public static function place_meta_box( $screen_id ): void {
		global $wp_meta_boxes;
		$side = $wp_meta_boxes[ $screen_id ]['side']['high'] ?? null;
		if ( ! is_array( $side ) || ! isset( $side['spl-payment-link'] ) ) {
			return;
		}
		$ours = array( 'spl-payment-link' => $side['spl-payment-link'] );
		unset( $side['spl-payment-link'] );
		$placed = array();
		foreach ( $side as $id => $box ) {
			$placed[ $id ] = $box;
			if ( 'woocommerce-order-actions' === $id ) {
				$placed += $ours;
			}
		}
		$wp_meta_boxes[ $screen_id ]['side']['high'] = $placed + $ours; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * Whether an order placed with another payment method can be sent an
	 * invoice: unpaid, not free, and On hold, Pending, Failed or Cancelled
	 * (WooCommerce cancels an abandoned card/crypto payment after "Hold stock"
	 * minutes; it can still be recovered with an invoice).
	 *
	 * @param WC_Order|null $order Order.
	 */
	private static function can_switch( $order ): bool {
		return $order instanceof WC_Order && ! SPL_Schedule::is_ours( $order ) && $order->get_total() > 0 && ! $order->is_paid()
			&& $order->has_status( array( 'on-hold', 'pending', 'failed', 'cancelled' ) );
	}

	/** The order being edited, or null. */
	private static function current_order(): ?WC_Order {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reading which order is open.
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : ( isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0 );
		// phpcs:enable
		$order = $id ? wc_get_order( $id ) : false;
		return $order instanceof WC_Order ? $order : null;
	}

	/**
	 * Date + time in the store's formats.
	 *
	 * @param int $time Timestamp.
	 */
	private static function when( int $time ): string {
		return wp_date( get_option( 'date_format' ) . ' · ' . get_option( 'time_format' ), $time );
	}

	/**
	 * Render the box.
	 *
	 * @param WP_Post|WC_Order $object The order (order tables) or its post.
	 */
	public static function render_box( $object ): void {
		$order = $object instanceof WC_Order ? $object : wc_get_order( $object->ID );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$link     = SPL_Schedule::link( $order );
		$on_hold  = $order->has_status( 'on-hold' );
		$started  = SPL_Schedule::started( $order );
		$stopped  = (int) $order->get_meta( SPL_Schedule::META_STOPPED );
		$next     = SPL_Schedule::next_run( $order );
		$plan     = SPL_Schedule::plan();
		$sent     = (int) $order->get_meta( SPL_Schedule::META_REMINDERS );
		$last     = (int) $order->get_meta( SPL_Schedule::META_LAST_SENT );
		$amount   = wp_strip_all_tags( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) );
		$editable = $on_hold;

		wp_nonce_field( 'spl_save_link', 'spl_nonce' );
		echo '<div class="spl-box">';

		if ( ! SPL_Schedule::is_ours( $order ) ) {
			self::render_switch_box( $order, $amount );
			echo '</div>';
			return;
		}

		if ( ! $on_hold ) {
			if ( $order->is_paid() ) {
				self::status( 'done', __( 'Paid — reminders ended', 'syssell-payment-link' ) );
			} elseif ( $order->get_meta( SPL_Schedule::META_EXPIRED ) ) {
				self::status( 'muted', __( 'Expired unpaid — order cancelled', 'syssell-payment-link' ) );
			} else {
				/* translators: %s: order status */
				self::status( 'muted', sprintf( __( '%s — no reminders', 'syssell-payment-link' ), wc_get_order_status_name( $order->get_status() ) ) );
			}
		} elseif ( '' === $link ) {
			self::status( 'warn', __( 'Waiting for a payment link', 'syssell-payment-link' ) );
			echo '<p>' . wp_kses(
				sprintf(
					/* translators: 1: amount, 2: customer email */
					__( 'Create a payment link for %1$s for %2$s (for example a Stripe invoice), paste it below and save. The customer gets it by email right away.', 'syssell-payment-link' ),
					'<strong>' . esc_html( $amount ) . '</strong>',
					'<strong>' . esc_html( $order->get_billing_email() ) . '</strong>'
				),
				array( 'strong' => array() )
			) . '</p>';
		} elseif ( ! $started ) {
			self::status( 'warn', __( 'Link saved but not emailed yet', 'syssell-payment-link' ) );
		} elseif ( $next ) {
			self::status( 'active', __( 'Link sent — reminders on', 'syssell-payment-link' ) );
		} else {
			self::status( 'warn', __( 'Link sent — reminders off', 'syssell-payment-link' ) );
		}

		if ( $started || $last ) {
			echo '<ul class="spl-facts">';
			if ( $started ) {
				/* translators: %s: date */
				echo '<li>' . esc_html( sprintf( __( 'First sent: %s', 'syssell-payment-link' ), self::when( $started ) ) ) . '</li>';
			}
			if ( $last && $last - $started > MINUTE_IN_SECONDS ) {
				/* translators: %s: date */
				echo '<li>' . esc_html( sprintf( __( 'Last emailed: %s', 'syssell-payment-link' ), self::when( $last ) ) ) . '</li>';
			}
			if ( $plan['reminders'] ) {
				/* translators: 1: reminders sent, 2: reminders planned */
				echo '<li>' . esc_html( sprintf( __( 'Reminders sent: %1$d of %2$d', 'syssell-payment-link' ), min( $sent, count( $plan['reminders'] ) ), count( $plan['reminders'] ) ) ) . '</li>';
			}
			if ( $on_hold && $next ) {
				$is_cancel = ! isset( $plan['reminders'][ $sent ] );
				echo '<li>' . esc_html(
					sprintf(
						$is_cancel
							/* translators: %s: date */
							? __( 'Cancels if unpaid: %s', 'syssell-payment-link' )
							/* translators: %s: date */
							: __( 'Next reminder: %s', 'syssell-payment-link' ),
						self::when( $next )
					)
				) . '</li>';
				$cancel = SPL_Schedule::cancel_time( $order );
				if ( ! $is_cancel && $cancel ) {
					/* translators: %s: date */
					echo '<li>' . esc_html( sprintf( __( 'Cancels if unpaid: %s', 'syssell-payment-link' ), wp_date( get_option( 'date_format' ), $cancel ) ) ) . '</li>';
				}
			}
			if ( $on_hold && $stopped ) {
				/* translators: %s: date */
				echo '<li>' . esc_html( sprintf( __( 'Stopped: %s — the order won’t be cancelled automatically', 'syssell-payment-link' ), self::when( $stopped ) ) ) . '</li>';
			}
			echo '</ul>';
		}

		if ( $editable ) {
			echo '<p class="spl-field"><label for="spl_link">' . esc_html__( 'Payment link', 'syssell-payment-link' ) . '</label>';
			echo '<input type="url" id="spl_link" name="spl_link" value="' . esc_attr( $link ) . '" placeholder="https://invoice.stripe.com/i/…" inputmode="url" autocomplete="off" spellcheck="false" /></p>';
		} elseif ( $link ) {
			echo '<p class="spl-field"><span class="spl-label">' . esc_html__( 'Payment link', 'syssell-payment-link' ) . '</span>';
			echo '<a class="spl-link" href="' . esc_url( $link ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $link ) . '</a></p>';
		}

		if ( $editable ) {
			echo '<div class="spl-actions">';
			if ( '' === $link ) {
				self::button( 'save', __( 'Save & email link', 'syssell-payment-link' ), true );
			} elseif ( ! $started ) {
				self::button( 'resend', __( 'Email link now', 'syssell-payment-link' ), true );
			} else {
				self::button( 'save', __( 'Save new link', 'syssell-payment-link' ), false );
				if ( $next ) {
					self::button( 'resend', __( 'Resend now', 'syssell-payment-link' ), false );
				} else {
					self::button( 'restart', __( 'Resend & restart reminders', 'syssell-payment-link' ), true );
				}
			}
			echo '</div>';
			if ( $next ) {
				echo '<p class="spl-stop"><button type="submit" class="button-link button-link-delete" name="spl_action" value="stop">' . esc_html__( 'Stop reminders', 'syssell-payment-link' ) . '</button></p>';
			}
			echo '<p class="spl-help">' . esc_html__( 'Once the payment arrives, change the order to Processing or Completed — that delivers the license and ends the reminders.', 'syssell-payment-link' ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * The box on an unpaid order placed with another payment method.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $amount Order total as text.
	 */
	private static function render_switch_box( WC_Order $order, string $amount ): void {
		$method = $order->get_payment_method_title() ? $order->get_payment_method_title() : $order->get_payment_method();
		self::status( 'warn', __( 'No invoice sent yet', 'syssell-payment-link' ) );
		echo '<p>' . wp_kses(
			sprintf(
				/* translators: 1: payment method, 2: order status, 3: amount, 4: customer email */
				__( 'Placed with %1$s (%2$s). To get paid by card, create an invoice for %3$s for %4$s (for example in Stripe), paste its payment link below and save. The customer gets it by email right away.', 'syssell-payment-link' ),
				'<strong>' . esc_html( $method ) . '</strong>',
				esc_html( wc_get_order_status_name( $order->get_status() ) ),
				'<strong>' . esc_html( $amount ) . '</strong>',
				'<strong>' . esc_html( $order->get_billing_email() ) . '</strong>'
			),
			array( 'strong' => array() )
		) . '</p>';
		if ( ! $order->has_status( 'on-hold' ) ) {
			echo '<p class="spl-status spl-status--warn">' . esc_html__( 'First set Status to On hold (left) — you can do both in one save.', 'syssell-payment-link' ) . '</p>';
		}
		echo '<p class="spl-field"><label for="spl_link">' . esc_html__( 'Payment link', 'syssell-payment-link' ) . '</label>';
		echo '<input type="url" id="spl_link" name="spl_link" value="" placeholder="https://invoice.stripe.com/i/…" inputmode="url" autocomplete="off" spellcheck="false" /></p>';
		echo '<div class="spl-actions">';
		self::button( 'save', __( 'Save & email link', 'syssell-payment-link' ), true );
		echo '</div>';
		/* translators: %s: payment method title */
		echo '<p class="spl-help">' . esc_html( sprintf( __( 'Sending the invoice switches this order to “%s”, with the same reminders and automatic cancel.', 'syssell-payment-link' ), SPL_Gateway::setting( 'title' ) ) ) . '</p>';
	}

	/**
	 * A status line.
	 *
	 * @param string $tone warn | active | done | muted.
	 * @param string $text Text.
	 */
	private static function status( string $tone, string $text ): void {
		echo '<p class="spl-status spl-status--' . esc_attr( $tone ) . '">' . esc_html( $text ) . '</p>';
	}

	/**
	 * A submit button inside the order form.
	 *
	 * @param string $action  Action value.
	 * @param string $label   Label.
	 * @param bool   $primary Primary style.
	 */
	private static function button( string $action, string $label, bool $primary ): void {
		printf( '<button type="submit" class="button%1$s" name="spl_action" value="%2$s">%3$s</button>', $primary ? ' button-primary' : '', esc_attr( $action ), esc_html( $label ) );
	}

	/**
	 * Handle the box after WooCommerce saved the order (status included).
	 *
	 * @param int $order_id Order ID.
	 */
	public static function save( $order_id ): void {
		if ( ! isset( $_POST['spl_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['spl_nonce'] ) ), 'spl_save_link' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		// Read after WooCommerce saved the form, so a status chosen in the same save counts.
		$order = wc_get_order( $order_id );
		$ours  = SPL_Schedule::is_ours( $order );
		if ( ! $ours && ! self::can_switch( $order ) ) {
			return;
		}

		$action  = isset( $_POST['spl_action'] ) ? sanitize_key( wp_unslash( $_POST['spl_action'] ) ) : '';
		$posted  = isset( $_POST['spl_link'] ) ? trim( (string) wp_unslash( $_POST['spl_link'] ) ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated below.
		$current = SPL_Schedule::link( $order );

		// A new or changed link (the box's Save button, or the order's own Update).
		if ( null !== $posted && $posted !== $current ) {
			if ( '' === $posted ) {
				if ( ! $ours ) {
					return;
				}
				SPL_Schedule::remove_link( $order );
				self::flash( 'success', __( 'Payment link removed. No more reminders will be sent.', 'syssell-payment-link' ) );
				return;
			}
			$link = self::validate( $posted );
			if ( '' === $link ) {
				self::flash( 'error', __( 'That payment link wasn’t saved: it must be a full address starting with https://', 'syssell-payment-link' ) );
				return;
			}
			if ( ! $ours ) {
				if ( ! $order->has_status( 'on-hold' ) ) {
					self::flash( 'error', __( 'The invoice wasn’t sent: set the order’s Status to On hold first (you can do both in one save).', 'syssell-payment-link' ) );
					return;
				}
				SPL_Schedule::adopt( $order );
			}
			$was_first = '' === $current;
			if ( SPL_Schedule::save_link( $order, $link ) ) {
				self::flash(
					'success',
					/* translators: %s: customer email */
					sprintf( $was_first ? __( 'Payment link emailed to %s. Reminders are on.', 'syssell-payment-link' ) : __( 'New payment link emailed to %s.', 'syssell-payment-link' ), $order->get_billing_email() )
				);
			} elseif ( $order->has_status( 'on-hold' ) ) {
				self::flash( 'error', __( 'The payment link was saved but the email could not be sent — see the order notes.', 'syssell-payment-link' ) );
			}
			return;
		}

		if ( ! $ours ) {
			if ( 'save' === $action ) {
				self::flash( 'error', __( 'Paste the payment link first.', 'syssell-payment-link' ) );
			}
			return;
		}

		switch ( $action ) {
			case 'save':
				if ( '' === $current ) {
					self::flash( 'error', __( 'Paste the payment link first.', 'syssell-payment-link' ) );
				}
				break;
			case 'resend':
				if ( '' !== $current && $order->has_status( 'on-hold' ) ) {
					$sent = SPL_Schedule::started( $order ) ? SPL_Schedule::resend( $order ) : SPL_Schedule::start( $order );
					/* translators: %s: customer email */
					self::flash( $sent ? 'success' : 'error', $sent ? sprintf( __( 'Payment link emailed to %s.', 'syssell-payment-link' ), $order->get_billing_email() ) : __( 'The email could not be sent — see the order notes.', 'syssell-payment-link' ) );
				}
				break;
			case 'restart':
				if ( '' !== $current && $order->has_status( 'on-hold' ) ) {
					$sent = SPL_Schedule::start( $order );
					self::flash( $sent ? 'success' : 'error', $sent ? __( 'Payment link emailed and reminders restarted.', 'syssell-payment-link' ) : __( 'The email could not be sent — see the order notes.', 'syssell-payment-link' ) );
				}
				break;
			case 'stop':
				SPL_Schedule::stop( $order );
				self::flash( 'success', __( 'Reminders stopped. The order won’t be cancelled automatically.', 'syssell-payment-link' ) );
				break;
		}
	}

	/**
	 * A clean https link, or ''.
	 *
	 * @param string $url Raw input.
	 */
	private static function validate( string $url ): string {
		$url   = esc_url_raw( $url, array( 'https' ) );
		$parts = $url ? wp_parse_url( $url ) : false;
		if ( ! $parts || 'https' !== ( $parts['scheme'] ?? '' ) || empty( $parts['host'] ) || false === strpos( $parts['host'], '.' ) ) {
			return '';
		}
		return $url;
	}

	/**
	 * Keep a message for the screen WooCommerce redirects to after saving.
	 *
	 * @param string $type    success | error.
	 * @param string $message Message.
	 */
	private static function flash( string $type, string $message ): void {
		set_transient( 'spl_notice_' . get_current_user_id(), array( $type, $message ), MINUTE_IN_SECONDS );
	}

	/**
	 * Show the kept message.
	 */
	public static function notice(): void {
		$key    = 'spl_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', 'error' === $notice[0] ? 'error' : 'success', esc_html( $notice[1] ) );
	}

	/**
	 * "Payment link" column after the status.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function add_column( $columns ): array {
		$out = array();
		foreach ( (array) $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$out['spl_payment_link'] = __( 'Payment link', 'syssell-payment-link' );
			}
		}
		if ( ! isset( $out['spl_payment_link'] ) ) {
			$out['spl_payment_link'] = __( 'Payment link', 'syssell-payment-link' );
		}
		return $out;
	}

	/**
	 * Column cell: only orders paid with this method get a badge.
	 *
	 * @param string       $column Column key.
	 * @param WC_Order|int $order  Order (order tables) or post ID.
	 */
	public static function render_column( $column, $order ): void {
		if ( 'spl_payment_link' !== $column ) {
			return;
		}
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( ! SPL_Schedule::is_ours( $order ) ) {
			return;
		}
		if ( $order->has_status( 'on-hold' ) ) {
			if ( '' === SPL_Schedule::link( $order ) || ! SPL_Schedule::started( $order ) ) {
				echo '<mark class="spl-badge spl-badge--warn">' . esc_html__( 'Link needed', 'syssell-payment-link' ) . '</mark>';
				return;
			}
			$plan = SPL_Schedule::plan();
			$sent = min( (int) $order->get_meta( SPL_Schedule::META_REMINDERS ), count( $plan['reminders'] ) );
			if ( SPL_Schedule::next_run( $order ) ) {
				/* translators: 1: reminders sent, 2: reminders planned */
				echo '<mark class="spl-badge spl-badge--active">' . esc_html( sprintf( __( 'Sent · %1$d/%2$d reminders', 'syssell-payment-link' ), $sent, count( $plan['reminders'] ) ) ) . '</mark>';
			} else {
				echo '<mark class="spl-badge spl-badge--warn">' . esc_html__( 'Sent · reminders off', 'syssell-payment-link' ) . '</mark>';
			}
			return;
		}
		if ( $order->is_paid() ) {
			echo '<mark class="spl-badge spl-badge--done">' . esc_html__( 'Paid', 'syssell-payment-link' ) . '</mark>';
		} elseif ( $order->get_meta( SPL_Schedule::META_EXPIRED ) ) {
			echo '<mark class="spl-badge spl-badge--muted">' . esc_html__( 'Expired', 'syssell-payment-link' ) . '</mark>';
		}
	}

	/**
	 * Small styles for the box and badges, on the order screens only.
	 */
	public static function styles(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$ids    = array( 'shop_order', 'edit-shop_order', function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : '' );
		if ( ! $screen || ! in_array( $screen->id, $ids, true ) ) {
			return;
		}
		?>
<style id="spl-admin">
.spl-box .spl-status{display:flex;align-items:center;gap:8px;margin:0 0 10px;font-weight:600}
.spl-box .spl-status::before{content:"";flex:0 0 8px;height:8px;border-radius:50%;background:#8c8f94}
.spl-box .spl-status--warn::before{background:#dba617}
.spl-box .spl-status--active::before{background:#2271b1}
.spl-box .spl-status--done::before{background:#00a32a}
.spl-box .spl-facts{margin:0 0 12px;color:#50575e}
.spl-box .spl-facts li{margin:0 0 3px}
.spl-box .spl-field label,.spl-box .spl-label{display:block;margin:0 0 4px;font-weight:600}
.spl-box .spl-field input{width:100%}
.spl-box .spl-link{word-break:break-all}
.spl-box .spl-actions{display:flex;flex-wrap:wrap;gap:6px}
.spl-box .spl-stop{margin:10px 0 0}
.spl-box .spl-help{margin:12px 0 0;color:#646970;font-size:12px}
.column-spl_payment_link{width:11ch}
.spl-badge{display:inline-block;padding:2px 8px;border-radius:4px;background:#f0f0f1;color:#50575e;font-size:12px;line-height:1.7;white-space:nowrap}
.spl-badge--warn{background:#fcf0c3;color:#6e4e00}
.spl-badge--active{background:#e5f0fa;color:#135e96}
.spl-badge--done{background:#e6f4ea;color:#00692a}
</style>
		<?php
	}
}
