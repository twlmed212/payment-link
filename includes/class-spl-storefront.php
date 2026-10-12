<?php
/**
 * What the customer sees outside the emails: the checkout logos, the order
 * status (License Shop theme), the first order email's wording, and the Meta
 * Pixel event for an order that isn't paid yet.
 *
 * @package SysSell_Payment_Link
 */

defined( 'ABSPATH' ) || exit;

/**
 * Storefront integration.
 */
class SPL_Storefront {

	/** Set while WooCommerce writes the "Order on-hold" email for one of our orders. */
	private static $rewording = false;

	/**
	 * Hook in.
	 */
	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'styles' ) );

		// License Shop theme: status card on the confirmation, View order and tracking pages.
		add_filter( 'license_shop_order_state', array( __CLASS__, 'order_state' ), 10, 2 );

		// License Shop theme: an unpaid order is a Lead for Meta, not a Purchase.
		add_filter( 'license_shop_meta_pixel_order_event', array( __CLASS__, 'pixel_event' ), 10, 2 );

		// "Order on-hold" email: our wording instead of "on hold until we confirm your payment".
		add_action( 'woocommerce_email_header', array( __CLASS__, 'email_start' ), 1, 2 );
		add_action( 'woocommerce_email_footer', array( __CLASS__, 'email_end' ), 99 );
		add_filter( 'gettext_woocommerce', array( __CLASS__, 'on_hold_wording' ), 10, 2 );
	}

	/**
	 * Checkout styles for the note and the logos (checkout and pay-for-order pages).
	 */
	public static function styles(): void {
		if ( function_exists( 'is_checkout' ) && is_checkout() && 'yes' === SPL_Gateway::setting( 'enabled' ) ) {
			wp_enqueue_style( 'spl-checkout', SPL_URL . 'assets/css/checkout.css', array(), SPL_VERSION );
		}
	}

	/** The order total as plain text, e.g. "$29.99". */
	public static function amount( WC_Order $order ): string {
		return html_entity_decode( wp_strip_all_tags( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES, 'UTF-8' );
	}

	/** "Pay invoice – $29.99". */
	public static function pay_label( WC_Order $order ): string {
		/* translators: %s: order total */
		return sprintf( __( 'Pay invoice – %s', 'syssell-payment-link' ), self::amount( $order ) );
	}

	/**
	 * The confirmation message (HTML), with the customer's email in bold.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function thank_you_text( WC_Order $order ): string {
		return str_replace( '%s', '<strong>' . esc_html( $order->get_billing_email() ) . '</strong>', esc_html( SPL_Gateway::setting( 'thank_you' ) ) );
	}

	/**
	 * Once the invoice was sent: where to, and that they can pay right here.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function link_ready_text( WC_Order $order ): string {
		/* translators: %s: customer email */
		return sprintf( __( 'We’ve emailed your invoice to %s. You can also pay it right here with any credit or debit card — it takes about a minute, and your license key follows as soon as your payment is confirmed.', 'syssell-payment-link' ), $order->get_billing_email() );
	}

	/**
	 * The theme's order status card for an On hold order of ours: "your invoice
	 * is on its way", then "your invoice is ready" with a Pay button once sent.
	 *
	 * @param array    $state Status { key, tone, title, text, steps[], action? }.
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function order_state( $state, $order ) {
		if ( ! is_array( $state ) || ! SPL_Schedule::is_ours( $order ) || ! $order->has_status( 'on-hold' ) ) {
			return $state;
		}
		$link = SPL_Schedule::link( $order );
		if ( '' !== $link && SPL_Schedule::started( $order ) ) {
			$state['tone']   = 'info';
			$state['title']  = __( 'Your invoice is ready', 'syssell-payment-link' );
			$state['text']   = str_replace( esc_html( $order->get_billing_email() ), '<strong>' . esc_html( $order->get_billing_email() ) . '</strong>', esc_html( self::link_ready_text( $order ) ) );
			$state['action'] = array(
				'url'      => $link,
				'label'    => self::pay_label( $order ),
				'external' => true,
			);
			return $state;
		}
		$state['tone']  = 'info';
		$state['title'] = __( 'Your invoice is on its way', 'syssell-payment-link' );
		$state['text']  = self::thank_you_text( $order );
		return $state;
	}

	/**
	 * Meta Pixel: our orders aren't paid at checkout, so they count as a Lead.
	 *
	 * @param string   $event Event name.
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function pixel_event( $event, $order ) {
		return SPL_Schedule::is_ours( $order ) && ! $order->is_paid() ? 'Lead' : $event;
	}

	/**
	 * An email is starting: note whether it's the on-hold email of one of our orders.
	 *
	 * @param string   $heading Heading.
	 * @param WC_Email $email   Email.
	 */
	public static function email_start( $heading, $email = null ): void {
		self::$rewording = is_object( $email ) && 'customer_on_hold_order' === $email->id && SPL_Schedule::is_ours( $email->object );
	}

	/**
	 * The email is done.
	 */
	public static function email_end(): void {
		self::$rewording = false;
	}

	/**
	 * Replace the on-hold email's opening line (HTML and plain text).
	 *
	 * @param string $translation Translated text.
	 * @param string $text        Original text.
	 * @return string
	 */
	public static function on_hold_wording( $translation, $text ) {
		if ( self::$rewording && in_array( $text, array( 'We’ve received your order and it’s currently on hold until we can confirm your payment has been processed.', 'Thanks for your order. It’s on-hold until we confirm that payment has been received.' ), true ) ) {
			$intro = trim( SPL_Gateway::setting( 'email_intro' ) );
			return '' !== $intro ? $intro : $translation;
		}
		return $translation;
	}
}
