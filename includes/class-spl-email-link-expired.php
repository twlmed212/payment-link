<?php
/**
 * Customer email sent when an unpaid order is cancelled at the end of the
 * payment link's reminder period.
 *
 * @package SysSell_Payment_Link
 */

defined( 'ABSPATH' ) || exit;

/**
 * "Card payment link expired" email (WooCommerce → Settings → Emails).
 */
class SPL_Email_Link_Expired extends WC_Email {

	/**
	 * Set up the email.
	 */
	public function __construct() {
		$this->id             = 'customer_payment_link_expired';
		$this->customer_email = true;
		$this->title          = __( 'Card payment link expired', 'syssell-payment-link' );
		$this->description    = __( 'Sent to the customer when an order paid by “Card Payment Link” is cancelled automatically because it stayed unpaid.', 'syssell-payment-link' );
		$this->template_html  = 'emails/spl-link-expired.php';
		$this->template_plain = 'emails/plain/spl-link-expired.php';
		$this->template_base  = SPL_PATH . 'templates/';
		$this->placeholders   = array(
			'{order_date}'   => '',
			'{order_number}' => '',
		);
		parent::__construct();
	}

	/** Default subject. */
	public function get_default_subject() {
		return __( 'Your order #{order_number} has been cancelled', 'syssell-payment-link' );
	}

	/** Default heading. */
	public function get_default_heading() {
		return __( 'Your order was cancelled', 'syssell-payment-link' );
	}

	/** Default closing text. */
	public function get_default_additional_content() {
		return __( 'Changed your mind or need help? Just reply to this email.', 'syssell-payment-link' );
	}

	/**
	 * Send it.
	 *
	 * @param int $order_id Order ID.
	 * @return bool Whether it was sent.
	 */
	public function trigger( $order_id ) {
		$this->setup_locale();
		$sent  = false;
		$order = wc_get_order( $order_id );

		if ( $order instanceof WC_Order ) {
			$this->object                         = $order;
			$this->recipient                      = $order->get_billing_email();
			$this->placeholders['{order_date}']   = wc_format_datetime( $order->get_date_created() );
			$this->placeholders['{order_number}'] = $order->get_order_number();

			if ( $this->is_enabled() && $this->get_recipient() ) {
				$sent = $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
			}
		}

		$this->restore_locale();
		return (bool) $sent;
	}

	/** Template arguments shared by the HTML and plain versions. */
	private function template_args( bool $plain ): array {
		return array(
			'order'              => $this->object,
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => false,
			'plain_text'         => $plain,
			'email'              => $this,
			'days'               => max( 0, (int) SPL_Gateway::setting( 'cancel_after' ) ),
			'shop_url'           => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' ),
		);
	}

	/** HTML content. */
	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->template_args( false ), '', $this->template_base );
	}

	/** Plain-text content. */
	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, $this->template_args( true ), '', $this->template_base );
	}
}
