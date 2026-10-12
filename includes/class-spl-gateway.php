<?php
/**
 * The checkout payment method. Nothing is charged at checkout: the order goes
 * On hold, and the customer pays later through the card payment link the store
 * sends from the order screen (see SPL_Admin / SPL_Schedule).
 *
 * @package SysSell_Payment_Link
 */

defined( 'ABSPATH' ) || exit;

/**
 * "Credit / Debit Card – Secure Payment Link" gateway.
 */
class SPL_Gateway extends WC_Payment_Gateway {

	const ID = 'syssell_payment_link';

	/**
	 * Every setting's default, in one place: the settings form, the checkout
	 * block and the reminder schedule all read through self::setting().
	 */
	public static function defaults(): array {
		return array(
			'enabled'       => 'no',
			'title'         => __( 'Credit / Debit Card – Secure Payment Link', 'syssell-payment-link' ),
			'description'   => __( 'Place your order now and we’ll email you a secure link to pay by card within a couple of hours — usually much sooner. No card details needed at checkout, and your license key is sent as soon as your payment is confirmed.', 'syssell-payment-link' ),
			'show_icons'    => 'yes',
			'thank_you'     => __( 'Thank you for your order! We’re preparing your secure card payment link and will email it to %s within a couple of hours — usually much sooner. Please keep an eye on your inbox (and your spam folder, just in case).', 'syssell-payment-link' ),
			'email_intro'   => __( 'Thank you for your order! We’re preparing your secure card payment link and will send it in a separate email within a couple of hours — usually much sooner. You can pay with any credit or debit card, and your license key follows as soon as your payment is confirmed.', 'syssell-payment-link' ),
			'reminder_days' => '2, 6, 13, 20, 27',
			'cancel_after'  => '30',
		);
	}

	/**
	 * A saved setting, or its default when it was never saved.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	public static function setting( string $key ): string {
		$saved    = get_option( 'woocommerce_' . self::ID . '_settings', array() );
		$defaults = self::defaults();
		if ( is_array( $saved ) && isset( $saved[ $key ] ) ) {
			return (string) $saved[ $key ];
		}
		return (string) ( $defaults[ $key ] ?? '' );
	}

	/**
	 * Card logos shown beside the method name (Stripe takes all major cards).
	 *
	 * @return array[] { id, src, alt }
	 */
	public static function card_icons(): array {
		return array(
			array( 'id' => 'spl-visa', 'src' => SPL_URL . 'assets/images/visa.svg', 'alt' => 'Visa' ),
			array( 'id' => 'spl-mastercard', 'src' => SPL_URL . 'assets/images/mastercard.svg', 'alt' => 'Mastercard' ),
			array( 'id' => 'spl-amex', 'src' => SPL_URL . 'assets/images/amex.svg', 'alt' => 'American Express' ),
		);
	}

	/**
	 * Set up the gateway.
	 */
	public function __construct() {
		$this->id                 = self::ID;
		$this->has_fields         = false;
		$this->method_title       = __( 'Card Payment Link', 'syssell-payment-link' );
		$this->method_description = __( 'Customers order now and pay by card later. The order waits On hold until you paste a card payment link (for example a Stripe invoice) in the “Card payment link” box on the order screen — the customer gets it by email straight away, then reminders until it is paid. Change the order to Processing or Completed once the payment arrives; that delivers the license.', 'syssell-payment-link' );
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
	}

	/**
	 * Settings under WooCommerce → Settings → Payments → Card Payment Link.
	 */
	public function init_form_fields() {
		$d = self::defaults();

		$this->form_fields = array(
			'enabled'       => array(
				'title'   => __( 'Enable/Disable', 'syssell-payment-link' ),
				'type'    => 'checkbox',
				'label'   => __( 'Offer “pay by card through a payment link” at checkout', 'syssell-payment-link' ),
				'default' => $d['enabled'],
			),
			'title'         => array(
				'title'       => __( 'Title', 'syssell-payment-link' ),
				'type'        => 'safe_text',
				'description' => __( 'The payment method name customers see at checkout, in emails and on their order.', 'syssell-payment-link' ),
				'default'     => $d['title'],
				'desc_tip'    => true,
			),
			'description'   => array(
				'title'       => __( 'Description', 'syssell-payment-link' ),
				'type'        => 'textarea',
				'description' => __( 'Shown under the method at checkout when it is selected.', 'syssell-payment-link' ),
				'default'     => $d['description'],
				'desc_tip'    => true,
			),
			'show_icons'    => array(
				'title'   => __( 'Card logos', 'syssell-payment-link' ),
				'type'    => 'checkbox',
				'label'   => __( 'Show Visa, Mastercard and American Express logos beside the title', 'syssell-payment-link' ),
				'default' => $d['show_icons'],
			),
			'thank_you'     => array(
				'title'       => __( 'Order confirmation message', 'syssell-payment-link' ),
				'type'        => 'textarea',
				'description' => __( 'Shown on the order confirmation page until the link is sent. %s is replaced with the customer’s email address.', 'syssell-payment-link' ),
				'default'     => $d['thank_you'],
				'desc_tip'    => true,
			),
			'email_intro'   => array(
				'title'       => __( 'Order email message', 'syssell-payment-link' ),
				'type'        => 'textarea',
				'description' => __( 'Replaces the first paragraph of WooCommerce’s “Order on-hold” email for these orders.', 'syssell-payment-link' ),
				'default'     => $d['email_intro'],
				'desc_tip'    => true,
			),
			'reminders'     => array(
				'title'       => __( 'Reminders', 'syssell-payment-link' ),
				'type'        => 'title',
				'description' => __( 'Counted from the day you send the payment link. Every reminder repeats the same link. Reminders stop as soon as the order leaves On hold.', 'syssell-payment-link' ),
			),
			'reminder_days' => array(
				'title'       => __( 'Send reminders on day', 'syssell-payment-link' ),
				'type'        => 'text',
				'description' => __( 'Days after the link was sent, separated by commas. The last one is worded as a final reminder.', 'syssell-payment-link' ),
				'default'     => $d['reminder_days'],
				'placeholder' => $d['reminder_days'],
			),
			'cancel_after'  => array(
				'title'             => __( 'Cancel unpaid orders after', 'syssell-payment-link' ),
				'type'              => 'number',
				'description'       => __( 'Days after the link was sent. The order is cancelled, its stock released, and the customer told. 0 = never cancel automatically.', 'syssell-payment-link' ),
				'default'           => $d['cancel_after'],
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
			),
		);
	}

	/**
	 * Card logos beside the title on the classic checkout.
	 *
	 * @return string
	 */
	public function get_icon() {
		$html = '';
		if ( 'yes' === $this->get_option( 'show_icons' ) ) {
			foreach ( self::card_icons() as $icon ) {
				$html .= sprintf( '<img src="%1$s" alt="%2$s" width="38" height="24" loading="lazy" />', esc_url( $icon['src'] ), esc_attr( $icon['alt'] ) );
			}
			$html = '<span class="spl-card-icons">' . $html . '</span>';
		}
		return apply_filters( 'woocommerce_gateway_icon', $html, $this->id );
	}

	/**
	 * Place the order without charging: On hold until the payment arrives.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( $order->get_total() > 0 ) {
			$order->update_status( 'on-hold', __( 'Awaiting card payment. Paste the payment link in the “Card payment link” box to email it to the customer.', 'syssell-payment-link' ) );
		} else {
			$order->payment_complete();
		}

		// Empty the cart only if it still matches this order.
		if ( WC()->cart && $order->has_cart_hash( WC()->cart->get_cart_hash() ) ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Order confirmation page. The License Shop theme shows this message in its
	 * own status card (via the license_shop_order_state filter, which has run by
	 * now), so it is printed here only for other themes.
	 *
	 * @param int $order_id Order ID.
	 */
	public function thankyou_page( $order_id ) {
		if ( function_exists( 'did_filter' ) && did_filter( 'license_shop_order_state' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->has_status( 'on-hold' ) ) {
			return;
		}
		$link = SPL_Schedule::link( $order );
		echo '<div class="spl-thankyou">';
		if ( $link ) {
			echo '<p>' . esc_html( SPL_Storefront::link_ready_text( $order ) ) . '</p>';
			echo '<p><a class="button" href="' . esc_url( $link ) . '" target="_blank" rel="noopener">' . esc_html( SPL_Storefront::pay_label( $order ) ) . '</a></p>';
		} else {
			echo wp_kses_post( wpautop( wptexturize( SPL_Storefront::thank_you_text( $order ) ) ) );
		}
		echo '</div>';
	}
}
