<?php
/**
 * Plugin Name: YS Helcim via FluentCart
 * Plugin URI: https://yangsheep.com.tw
 * Description: Adds Helcim as a payment gateway for FluentCart — offering both a HelcimPay.js modal checkout and a helcim.js inline card form, complete with refunds and webhook reconciliation.
 * Version: 1.1.0-rc.2
 * Author: YANGSHEEP DESIGN
 * Author URI: https://yangsheep.com.tw
 * Text Domain: ys-helcim-via-fluentcart
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.1
 *
 * @package YangSheep\Helcim\FluentCart
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Plugin constants ─────────────────────────────────────────────────────────

define( 'YS_HELCIM_FCT_VERSION', '1.1.0-rc.2' );
define( 'YS_HELCIM_FCT_FILE', __FILE__ );
define( 'YS_HELCIM_FCT_DIR', plugin_dir_path( __FILE__ ) );
define( 'YS_HELCIM_FCT_URL', plugin_dir_url( __FILE__ ) );

// ── Vendor autoload (YS Plugin Hub Client — enables auto-updates) ─────────────
// The Hub client is self-contained (its own PSR-4 autoloader + duplicate-load
// guard) and does not depend on YS CART or WooCommerce.

if ( file_exists( YS_HELCIM_FCT_DIR . 'vendor/autoload.php' ) ) {
	require_once YS_HELCIM_FCT_DIR . 'vendor/autoload.php';
}

// ── PSR-4 autoloader ─────────────────────────────────────────────────────────
// Namespace YangSheep\Helcim\FluentCart\ maps to the src/ directory.

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'YangSheep\\Helcim\\FluentCart\\';

		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$file     = YS_HELCIM_FCT_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook(
	YS_HELCIM_FCT_FILE,
	array( \YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationSchema::class, 'activate' )
);
register_activation_hook(
	YS_HELCIM_FCT_FILE,
	array( \YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundResolutionSchema::class, 'activate' )
);
register_deactivation_hook(
	YS_HELCIM_FCT_FILE,
	array( \YangSheep\Helcim\FluentCart\YSHelcimFctBootstrap::class, 'deactivate' )
);

// ── Bootstrap ────────────────────────────────────────────────────────────────

\YangSheep\Helcim\FluentCart\YSHelcimFctBootstrap::init();

// ── YS Plugin Hub Client registration (priority 5, before the main bootstrap) ─
// Registers this plugin with the YS Plugin Hub so it can receive auto-updates.

add_action(
	'plugins_loaded',
	static function () {
		if ( class_exists( '\\YangSheep\\PluginHubClient\\YSPluginHubClient' ) ) {
			\YangSheep\PluginHubClient\YSPluginHubClient::register(
				array(
					'slug'        => 'ys-helcim-via-fluentcart',
					'version'     => YS_HELCIM_FCT_VERSION,
					'plugin_file' => YS_HELCIM_FCT_FILE,
					'name'        => 'YS Helcim via FluentCart',
				)
			);
		}
	},
	5
);
