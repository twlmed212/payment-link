<?php
/**
 * Plugin Name:          SysSell Payment Link
 * Description:          "Credit / Debit Card – Pay by Invoice" checkout method. The order waits On hold until you paste the invoice's card payment link (for example a Stripe invoice) on the order screen; the customer gets the invoice by email, with reminders until it is paid or the order expires.
 * Version:              1.2.0
 * Author:               TwlMed212
 * Requires at least:    6.5
 * Requires PHP:         7.4
 * WC requires at least: 8.6
 * WC tested up to:      11.1
 * Text Domain:          syssell-payment-link
 * Domain Path:          /languages
 * License:              GPL-2.0-or-later
 *
 * @package SysSell_Payment_Link
 */

defined( 'ABSPATH' ) || exit;

define( 'SPL_VERSION', '1.2.0' );
define( 'SPL_FILE', __FILE__ );
define( 'SPL_PATH', plugin_dir_path( __FILE__ ) );
define( 'SPL_URL', plugin_dir_url( __FILE__ ) );

// Works with WooCommerce's order tables (HPOS) and the Cart & Checkout blocks.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', SPL_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', SPL_FILE, true );
		}
	}
);

add_action( 'plugins_loaded', 'spl_init', 11 );

/**
 * Boot once WooCommerce is loaded. Without WooCommerce the plugin stays idle.
 */
function spl_init(): void {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	load_plugin_textdomain( 'syssell-payment-link', false, dirname( plugin_basename( SPL_FILE ) ) . '/languages' );

	require_once SPL_PATH . 'includes/class-spl-gateway.php';
	require_once SPL_PATH . 'includes/class-spl-schedule.php';
	require_once SPL_PATH . 'includes/class-spl-admin.php';
	require_once SPL_PATH . 'includes/class-spl-storefront.php';

	add_filter(
		'woocommerce_payment_gateways',
		static function ( $gateways ) {
			$gateways[] = 'SPL_Gateway';
			return $gateways;
		}
	);

	add_filter(
		'woocommerce_email_classes',
		static function ( $emails ) {
			require_once SPL_PATH . 'includes/class-spl-email-payment-link.php';
			require_once SPL_PATH . 'includes/class-spl-email-link-expired.php';
			$emails['SPL_Email_Payment_Link'] = new SPL_Email_Payment_Link();
			$emails['SPL_Email_Link_Expired'] = new SPL_Email_Link_Expired();
			return $emails;
		}
	);

	add_action(
		'woocommerce_blocks_payment_method_type_registration',
		static function ( $registry ) {
			require_once SPL_PATH . 'includes/class-spl-blocks.php';
			$registry->register( new SPL_Blocks() );
		}
	);

	SPL_Gateway::maybe_upgrade();
	SPL_Schedule::init();
	SPL_Admin::init();
	SPL_Storefront::init();
}

// Pending reminders don't run while the plugin is off; turning it back on
// re-plans them from each order's saved dates.
register_deactivation_hook(
	__FILE__,
	static function () {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'syssell_payment_link_tick' );
		}
	}
);

register_activation_hook(
	__FILE__,
	static function () {
		update_option( 'spl_reschedule_pending', 'yes', false );
	}
);
