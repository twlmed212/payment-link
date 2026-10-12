<?php
/**
 * Card invoice expired email (plain text).
 *
 * Override by copying to yourtheme/woocommerce/emails/plain/spl-link-expired.php.
 *
 * @package SysSell_Payment_Link
 *
 * @var WC_Order $order
 * @var string   $email_heading
 * @var string   $additional_content
 * @var bool     $sent_to_admin
 * @var bool     $plain_text
 * @var WC_Email $email
 * @var int      $days
 * @var string   $shop_url
 */

defined( 'ABSPATH' ) || exit;

echo "=================================================================\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=================================================================\n\n";

/* translators: %s: customer first name */
echo $order->get_billing_first_name() ? esc_html( sprintf( __( 'Hi %s,', 'syssell-payment-link' ), $order->get_billing_first_name() ) ) : esc_html__( 'Hi,', 'syssell-payment-link' );
echo "\n\n";

echo esc_html(
	$days
		/* translators: 1: order number, 2: number of days */
		? sprintf( __( 'We didn’t receive a payment for order #%1$s within %2$d days, so we’ve cancelled it. Nothing was charged.', 'syssell-payment-link' ), $order->get_order_number(), $days )
		/* translators: %s: order number */
		: sprintf( __( 'We didn’t receive a payment for order #%s, so we’ve cancelled it. Nothing was charged.', 'syssell-payment-link' ), $order->get_order_number() )
);
echo "\n\n";
echo esc_html__( 'Still want it? You’re welcome to place a new order at any time — or simply reply to this email and we’ll help you complete it.', 'syssell-payment-link' ) . "\n";
echo esc_url_raw( $shop_url ) . "\n\n";

echo "----------------------------------------\n\n";

do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

echo "\n----------------------------------------\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n\n----------------------------------------\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
