<?php
/**
 * Card payment link expired email (HTML).
 *
 * Override by copying to yourtheme/woocommerce/emails/spl-link-expired.php.
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

$base    = sanitize_hex_color( (string) get_option( 'woocommerce_email_base_color', '#2563eb' ) );
$base    = $base ? $base : '#2563eb';
$on_base = function_exists( 'wc_light_or_dark' ) ? wc_light_or_dark( $base, '#111827', '#ffffff' ) : '#ffffff';

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p>
	<?php
	/* translators: %s: customer first name */
	echo $order->get_billing_first_name() ? esc_html( sprintf( __( 'Hi %s,', 'syssell-payment-link' ), $order->get_billing_first_name() ) ) : esc_html__( 'Hi,', 'syssell-payment-link' );
	?>
</p>

<p>
	<?php
	echo esc_html(
		$days
			/* translators: 1: order number, 2: number of days */
			? sprintf( __( 'We didn’t receive a payment for order #%1$s within %2$d days, so we’ve cancelled it. Nothing was charged.', 'syssell-payment-link' ), $order->get_order_number(), $days )
			/* translators: %s: order number */
			: sprintf( __( 'We didn’t receive a payment for order #%s, so we’ve cancelled it. Nothing was charged.', 'syssell-payment-link' ), $order->get_order_number() )
	);
	?>
</p>

<p><?php esc_html_e( 'Still want it? You’re welcome to place a new order at any time — or simply reply to this email and we’ll help you complete it.', 'syssell-payment-link' ); ?></p>

<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin:8px 0 28px;">
	<tr>
		<td align="center" style="padding:0;">
			<table role="presentation" border="0" cellpadding="0" cellspacing="0">
				<tr>
					<td style="padding:0;border-radius:10px;background:<?php echo esc_attr( $base ); ?>;">
						<a href="<?php echo esc_url( $shop_url ); ?>" style="display:inline-block;padding:14px 30px;font-size:15px;font-weight:bold;color:<?php echo esc_attr( $on_base ); ?>;text-decoration:none;border-radius:10px;"><?php esc_html_e( 'Visit the store', 'syssell-payment-link' ); ?></a>
					</td>
				</tr>
			</table>
		</td>
	</tr>
</table>

<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
