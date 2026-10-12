<?php
/**
 * Invoice email — the card payment link (HTML).
 *
 * Override by copying to yourtheme/woocommerce/emails/spl-payment-link.php.
 *
 * @package SysSell_Payment_Link
 *
 * @var WC_Order $order
 * @var string   $email_heading
 * @var string   $additional_content
 * @var bool     $sent_to_admin
 * @var bool     $plain_text
 * @var WC_Email $email
 * @var string   $variant     initial | updated | reminder | final
 * @var string   $link
 * @var string   $cancel_date
 * @var string   $amount
 * @var bool     $by_stripe
 */

defined( 'ABSPATH' ) || exit;

$base     = sanitize_hex_color( (string) get_option( 'woocommerce_email_base_color', '#2563eb' ) );
$base     = $base ? $base : '#2563eb';
$on_base  = function_exists( 'wc_light_or_dark' ) ? wc_light_or_dark( $base, '#111827', '#ffffff' ) : '#ffffff';
$tint     = function_exists( 'wc_hex_lighter' ) ? wc_hex_lighter( $base, 93 ) : '#f3f6fb';
$line     = function_exists( 'wc_hex_lighter' ) ? wc_hex_lighter( $base, 80 ) : '#dbe3ef';
$number   = $order->get_order_number();
$reminder = in_array( $variant, array( 'reminder', 'final' ), true );

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p>
	<?php
	/* translators: %s: customer first name */
	echo $order->get_billing_first_name() ? esc_html( sprintf( __( 'Hi %s,', 'syssell-payment-link' ), $order->get_billing_first_name() ) ) : esc_html__( 'Hi,', 'syssell-payment-link' );
	?>
</p>

<p>
	<?php
	switch ( $variant ) {
		case 'updated':
			/* translators: %s: order number */
			echo esc_html( sprintf( __( 'We’ve updated the invoice for your order #%s. Please use the button below — it replaces the invoice we sent earlier.', 'syssell-payment-link' ), $number ) );
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
	?>
</p>

<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin:8px 0 16px;">
	<tr>
		<td align="center" style="padding:0;">
			<table role="presentation" border="0" cellpadding="0" cellspacing="0">
				<tr>
					<td style="padding:0;border-radius:10px;background:<?php echo esc_attr( $base ); ?>;">
						<a href="<?php echo esc_url( $link ); ?>" style="display:inline-block;padding:15px 32px;font-size:16px;font-weight:bold;color:<?php echo esc_attr( $on_base ); ?>;text-decoration:none;border-radius:10px;">
							<?php
							/* translators: %s: order total */
							echo esc_html( sprintf( __( 'Pay invoice – %s', 'syssell-payment-link' ), $amount ) );
							?>
						</a>
					</td>
				</tr>
			</table>
		</td>
	</tr>
</table>

<p style="margin:0 0 24px;font-size:12px;line-height:1.5;text-align:center;word-break:break-all;">
	<?php esc_html_e( 'Button not working? Copy this link into your browser:', 'syssell-payment-link' ); ?><br>
	<a href="<?php echo esc_url( $link ); ?>" style="color:<?php echo esc_attr( $base ); ?>;"><?php echo esc_html( $link ); ?></a>
</p>

<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin:0 0 26px;">
	<tr>
		<td style="padding:14px 18px;border:1px solid <?php echo esc_attr( $line ); ?>;border-left:3px solid <?php echo esc_attr( $base ); ?>;border-radius:10px;background:<?php echo esc_attr( $tint ); ?>;font-size:14px;line-height:1.6;">
			<strong><?php echo $by_stripe ? esc_html__( 'Secure checkout by Stripe', 'syssell-payment-link' ) : esc_html__( 'Secure card checkout', 'syssell-payment-link' ); ?></strong><br>
			<?php esc_html_e( 'Visa, Mastercard, American Express and other major cards are accepted. We never see or store your card details.', 'syssell-payment-link' ); ?>
			<?php if ( $reminder ) : ?>
				<br><br><strong><?php esc_html_e( 'Already paid?', 'syssell-payment-link' ); ?></strong>
				<?php esc_html_e( 'Please ignore this email — we’ll confirm your payment and send your license shortly.', 'syssell-payment-link' ); ?>
			<?php endif; ?>
		</td>
	</tr>
</table>

<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
