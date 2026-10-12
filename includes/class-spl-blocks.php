<?php
/**
 * The payment method in the Checkout block.
 *
 * @package SysSell_Payment_Link
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Checkout block integration: same title, description and logos as the classic checkout.
 */
final class SPL_Blocks extends AbstractPaymentMethodType {

	/**
	 * Payment method name (the gateway ID).
	 *
	 * @var string
	 */
	protected $name = SPL_Gateway::ID;

	/**
	 * Nothing to load: settings are read through SPL_Gateway::setting().
	 */
	public function initialize() {}

	/** Offered when enabled in WooCommerce → Settings → Payments. */
	public function is_active() {
		return 'yes' === SPL_Gateway::setting( 'enabled' );
	}

	/** The block script. */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'spl-checkout-block',
			SPL_URL . 'assets/js/checkout-block.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			SPL_VERSION,
			true
		);
		return array( 'spl-checkout-block' );
	}

	/** Data for the script. */
	public function get_payment_method_data() {
		return array(
			'title'       => SPL_Gateway::setting( 'title' ),
			'description' => SPL_Gateway::setting( 'description' ),
			'icons'       => 'yes' === SPL_Gateway::setting( 'show_icons' ) ? SPL_Gateway::card_icons() : array(),
			'supports'    => $this->get_supported_features(),
		);
	}
}
