<?php
/**
 * Invoice email — the card payment link (plain text).
 *
 * Override by copying to yourtheme/woocommerce/emails/plain/spl-payment-link.php.
 *
 * @package SysSell_Payment_Link
 *
 * @var WC_Order $order
 * @var string   $email_heading
 * @var string   $additional_content
 * @var bool     $sent_to_admin
 * @var bool     $plain_text
 * @var WC_Email $email
 * @var string   $variant
 * @var string   $link
 * @var string   $cancel_date
 * @var string   $amount
 * @var bool     $by_stripe
 */

defined( 'ABSPATH' ) || exit;

$number = $order->get_order_number();

echo "=================================================================\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=================================================================\n\n";

/* translators: %s: customer first name */
echo $order->get_billing_first_name() ? esc_html( sprintf( __( 'Hi %s,', 'syssell-payment-link' ), $order->get_billing_first_name() ) ) : esc_html__( 'Hi,', 'syssell-payment-link' );
echo "\n\n";

switch ( $variant ) {
	case 'updated':
		/* translators: %s: order number */
		echo esc_html( sprintf( __( 'We’ve updated the invoice for your order #%s. Please use the link below — it replaces the invoice we sent earlier.', 'syssell-payment-link' ), $number ) );
		break;
	case 'reminder':
		/* translators: %s: order number */
		echo esc_html( sprintf( __( 'Just a friendly reminder — the invoice for your order #%s is still open. Your order is reserved for you, and you can pay securely with any credit or debit card in about a minute.', 'syssell-payment-link' ), $number ) );
		break;
	case 'final':
		echo esc_html(
			$cancel_date
				/* translators: 1: order number, 2: date */
				? sprintf( __( 'The invoice for your order #%1$s is still unpaid. We’ll keep your order reserved until %2$s — after that it will be cancelled automatically. If you still want it, you can pay with any credit or debit card in about a minute.', 'syssell-payment-link' ), $number, $cancel_date )
				/* translators: %s: order number */
				: sprintf( __( 'The invoice for your order #%s is still unpaid. If you still want the order, you can pay with any credit or debit card in about a minute.', 'syssell-payment-link' ), $number )
		);
		break;
	default:
		/* translators: %s: order number */
		echo esc_html( sprintf( __( 'Thank you for your order. Your invoice for order #%s is ready — you can pay it securely with any credit or debit card in about a minute, and your license key will be on its way as soon as your payment is confirmed.', 'syssell-payment-link' ), $number ) );
}
echo "\n\n";

/* translators: %s: order total */
echo esc_html( sprintf( __( 'Pay your invoice (%s) securely:', 'syssell-payment-link' ), $amount ) ) . "\n";
echo esc_url_raw( $link ) . "\n\n";

echo $by_stripe ? esc_html__( 'Secure checkout by Stripe.', 'syssell-payment-link' ) : esc_html__( 'Secure card checkout.', 'syssell-payment-link' );
echo ' ' . esc_html__( 'Visa, Mastercard, American Express and other major cards are accepted. We never see or store your card details.', 'syssell-payment-link' ) . "\n\n";

if ( in_array( $variant, array( 'reminder', 'final' ), true ) ) {
	echo esc_html__( 'Already paid? Please ignore this email — we’ll confirm your payment and send your license shortly.', 'syssell-payment-link' ) . "\n\n";
}

echo "----------------------------------------\n\n";

do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

echo "\n----------------------------------------\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n\n----------------------------------------\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
