<?php
/**
 * Customer email carrying the invoice (the card payment link). One email, four wordings:
 * initial (first send, or "Resend"), updated (a new invoice), reminder,
 * and final (the last reminder, with the cancel date).
 *
 * @package SysSell_Payment_Link
 */

defined( 'ABSPATH' ) || exit;

/**
 * "Card invoice" email (WooCommerce → Settings → Emails).
 */
class SPL_Email_Payment_Link extends WC_Email {

	/**
	 * Wording: initial | updated | reminder | final.
	 *
	 * @var string
	 */
	public $variant = 'initial';

	/**
	 * The payment link being sent.
	 *
	 * @var string
	 */
	public $link = '';

	/**
	 * Set up the email.
	 */
	public function __construct() {
		$this->id             = 'customer_payment_link';
		$this->customer_email = true;
		$this->title          = __( 'Card invoice', 'syssell-payment-link' );
		$this->description    = __( 'The invoice email: sent to the customer when you add the card payment link to an order paid “by invoice”, then as reminders until the order is paid or expires.', 'syssell-payment-link' );
		$this->template_html  = 'emails/spl-payment-link.php';
		$this->template_plain = 'emails/plain/spl-payment-link.php';
		$this->template_base  = SPL_PATH . 'templates/';
		$this->placeholders   = array(
			'{order_date}'   => '',
			'{order_number}' => '',
			'{cancel_date}'  => '',
		);
		parent::__construct();
	}

	/** Default subject (first email). */
	public function get_default_subject() {
		return __( 'Your invoice for order #{order_number}', 'syssell-payment-link' );
	}

	/** Default heading (first email). */
	public function get_default_heading() {
		return __( 'Your invoice is ready', 'syssell-payment-link' );
	}

	/**
	 * Default subject/heading per wording.
	 *
	 * @param string $key subject | heading | subject_reminder | heading_reminder | subject_final | heading_final.
	 */
	private function default_for( string $key ): string {
		switch ( $key ) {
			case 'subject_reminder':
				return __( 'Reminder: your invoice for order #{order_number} is still open', 'syssell-payment-link' );
			case 'heading_reminder':
				return __( 'Your order is waiting for you', 'syssell-payment-link' );
			case 'subject_final':
				return __( 'Last reminder: order #{order_number} will be cancelled on {cancel_date}', 'syssell-payment-link' );
			case 'heading_final':
				return __( 'Last reminder', 'syssell-payment-link' );
			case 'heading':
				return $this->get_default_heading();
			default:
				return $this->get_default_subject();
		}
	}

	/**
	 * The setting key for this wording ("updated" shares the first email's).
	 *
	 * @param string $base subject | heading.
	 */
	private function key_for( string $base ): string {
		return in_array( $this->variant, array( 'reminder', 'final' ), true ) ? $base . '_' . $this->variant : $base;
	}

	/** Subject for the current wording. */
	public function get_subject() {
		$key = $this->key_for( 'subject' );
		return apply_filters( 'woocommerce_email_subject_' . $this->id, $this->format_string( $this->get_option( $key, $this->default_for( $key ) ) ), $this->object, $this );
	}

	/** Heading for the current wording. */
	public function get_heading() {
		$key = $this->key_for( 'heading' );
		return apply_filters( 'woocommerce_email_heading_' . $this->id, $this->format_string( $this->get_option( $key, $this->default_for( $key ) ) ), $this->object, $this );
	}

	/** Default closing text. */
	public function get_default_additional_content() {
		return __( 'Questions about your order? Just reply to this email — we’re happy to help.', 'syssell-payment-link' );
	}

	/**
	 * Send the link.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $variant  initial | updated | reminder | final.
	 * @return bool Whether it was sent.
	 */
	public function trigger( $order_id, $variant = 'initial' ) {
		$this->setup_locale();
		$sent  = false;
		$order = wc_get_order( $order_id );

		if ( $order instanceof WC_Order ) {
			$this->object                         = $order;
			$this->variant                        = in_array( $variant, array( 'initial', 'updated', 'reminder', 'final' ), true ) ? $variant : 'initial';
			$this->link                           = SPL_Schedule::link( $order );
			$this->recipient                      = $order->get_billing_email();
			$this->placeholders['{order_date}']   = wc_format_datetime( $order->get_date_created() );
			$this->placeholders['{order_number}'] = $order->get_order_number();
			$this->placeholders['{cancel_date}']  = SPL_Schedule::cancel_date( $order );

			if ( $this->is_enabled() && $this->get_recipient() && '' !== $this->link ) {
				$sent = $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
			}
		}

		$this->restore_locale();
		return (bool) $sent;
	}

	/** Template arguments shared by the HTML and plain versions. */
	private function template_args( bool $plain ): array {
		$order   = $this->object;
		$preview = (bool) apply_filters( 'woocommerce_is_email_preview', false );
		$link    = '' !== $this->link ? $this->link : ( $preview ? 'https://invoice.stripe.com/i/example' : '' );
		$cancel  = $order instanceof WC_Order ? SPL_Schedule::cancel_date( $order ) : '';
		if ( '' === $cancel && $preview ) {
			$cancel = wp_date( get_option( 'date_format' ), time() + 30 * DAY_IN_SECONDS );
		}
		return array(
			'order'              => $order,
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => false,
			'plain_text'         => $plain,
			'email'              => $this,
			'variant'            => $this->variant,
			'link'               => $link,
			'cancel_date'        => $cancel,
			'amount'             => $order instanceof WC_Order ? SPL_Storefront::amount( $order ) : '',
			'by_stripe'          => (bool) preg_match( '/(^|\.)stripe\.com$/i', (string) wp_parse_url( $link, PHP_URL_HOST ) ),
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

	/**
	 * Settings: one subject + heading per wording.
	 */
	public function init_form_fields() {
		/* translators: %s: list of placeholders */
		$placeholder_text = sprintf( __( 'Available placeholders: %s', 'syssell-payment-link' ), '<code>' . implode( '</code>, <code>', array_map( 'esc_html', array_keys( array_merge( $this->placeholders, array( '{site_title}' => '' ) ) ) ) ) . '</code>' );
		$text             = static function ( string $title, string $placeholder ) use ( $placeholder_text ): array {
			return array(
				'title'       => $title,
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => $placeholder_text,
				'placeholder' => $placeholder,
				'default'     => '',
			);
		};

		$this->form_fields = array(
			'enabled'            => array(
				'title'   => __( 'Enable/Disable', 'syssell-payment-link' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable this email', 'syssell-payment-link' ),
				'default' => 'yes',
			),
			'subject'            => $text( __( 'Subject — invoice', 'syssell-payment-link' ), $this->default_for( 'subject' ) ),
			'heading'            => $text( __( 'Heading — invoice', 'syssell-payment-link' ), $this->default_for( 'heading' ) ),
			'subject_reminder'   => $text( __( 'Subject — reminder', 'syssell-payment-link' ), $this->default_for( 'subject_reminder' ) ),
			'heading_reminder'   => $text( __( 'Heading — reminder', 'syssell-payment-link' ), $this->default_for( 'heading_reminder' ) ),
			'subject_final'      => $text( __( 'Subject — last reminder', 'syssell-payment-link' ), $this->default_for( 'subject_final' ) ),
			'heading_final'      => $text( __( 'Heading — last reminder', 'syssell-payment-link' ), $this->default_for( 'heading_final' ) ),
			'additional_content' => array(
				'title'       => __( 'Additional content', 'syssell-payment-link' ),
				'description' => __( 'Text to appear below the main email content.', 'syssell-payment-link' ) . ' ' . $placeholder_text,
				'css'         => 'width:400px; height: 75px;',
				'placeholder' => __( 'N/A', 'syssell-payment-link' ),
				'type'        => 'textarea',
				'default'     => $this->get_default_additional_content(),
				'desc_tip'    => true,
			),
			'email_type'         => array(
				'title'       => __( 'Email type', 'syssell-payment-link' ),
				'type'        => 'select',
				'description' => __( 'Choose which format of email to send.', 'syssell-payment-link' ),
				'default'     => 'html',
				'class'       => 'email_type wc-enhanced-select',
				'options'     => $this->get_email_type_options(),
				'desc_tip'    => true,
			),
		);
	}
}
