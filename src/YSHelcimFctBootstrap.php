<?php
/**
 * YS Helcim FluentCart Bootstrap — plugin bootstrapper.
 *
 * Responsibilities: check the FluentCart dependency and register both payment
 * methods with FluentCart (ys_helcim = HelcimPay.js modal, ys_helcim_js =
 * helcim.js inline form).
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart;

use YangSheep\Helcim\FluentCart\HelcimPay\YSHelcimPayGateway;
use YangSheep\Helcim\FluentCart\Support\YSHelcimLogger;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin bootstrapper (singleton).
 *
 * Timeline: on plugins_loaded we check for FluentCart, then on
 * fluent_cart/register_payment_methods (fired by FluentCart during init) we
 * register the gateways.
 */
final class YSHelcimFctBootstrap {

	/**
	 * Singleton instance.
	 *
	 * @var YSHelcimFctBootstrap|null
	 */
	private static $instance = null;

	/**
	 * Get (or create) the single instance and hook up the bootstrap actions.
	 *
	 * @return YSHelcimFctBootstrap
	 */
	public static function init(): YSHelcimFctBootstrap {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor: hook into plugins_loaded (private — only reachable via init()).
	 */
	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'onPluginsLoaded' ) );
	}

	/**
	 * plugins_loaded callback: check whether FluentCart is active.
	 *
	 * If it is not active, show an admin notice and register no gateways.
	 * If it is active, hook into FluentCart's gateway registration action.
	 *
	 * @return void
	 */
	public function onPluginsLoaded(): void {
		// Load translations before any __() call runs (earliest safe point).
		load_plugin_textdomain(
			'ys-helcim-via-fluentcart',
			false,
			dirname( plugin_basename( YS_HELCIM_FCT_FILE ) ) . '/languages'
		);

		if ( ! $this->isFluentCartActive() ) {
			add_action( 'admin_notices', array( $this, 'renderMissingFluentCartNotice' ) );
			return;
		}

		// FluentCart fires this action inside its init hook (GlobalPaymentHandler::init).
		add_action( 'fluent_cart/register_payment_methods', array( $this, 'registerGateways' ) );
	}

	/**
	 * Check whether FluentCart is active.
	 *
	 * @return bool
	 */
	public function isFluentCartActive(): bool {
		return defined( 'FLUENTCART_VERSION' ) || function_exists( 'fluent_cart_api' );
	}

	/**
	 * Register the payment methods with FluentCart.
	 *
	 * ys_helcim (the HelcimPay.js modal) is implemented in this lane;
	 * ys_helcim_js (the helcim.js inline form) is implemented in another lane
	 * and registered defensively behind class_exists.
	 *
	 * @return void
	 */
	public function registerGateways(): void {
		if ( ! function_exists( 'fluent_cart_api' ) ) {
			return;
		}

		try {
			fluent_cart_api()->registerCustomPaymentMethod( 'ys_helcim', new YSHelcimPayGateway() );

			// The helcim.js inline gateway is built by another developer — skip it if the file is not present yet.
			$js_gateway_class = '\\YangSheep\\Helcim\\FluentCart\\HelcimJs\\YSHelcimJsGateway';
			if ( class_exists( $js_gateway_class ) ) {
				fluent_cart_api()->registerCustomPaymentMethod( 'ys_helcim_js', new $js_gateway_class() );
			}
		} catch ( \Throwable $e ) {
			// A registration failure must not take down the whole site — log it and return quietly.
			YSHelcimLogger::error(
				'Gateway registration failed',
				array( 'error' => $e->getMessage() )
			);
		}
	}

	/**
	 * Admin notice: FluentCart is not active.
	 *
	 * @return void
	 */
	public function renderMissingFluentCartNotice(): void {
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'YS Helcim via FluentCart requires the FluentCart plugin (1.5.2 or later) to be installed and activated.', 'ys-helcim-via-fluentcart' )
			. '</p></div>';
	}

	/**
	 * Prevent cloning (singleton).
	 */
	private function __clone() {
	}

	/**
	 * Prevent unserialization (singleton).
	 *
	 * @throws \RuntimeException Always.
	 */
	public function __wakeup() {
		throw new \RuntimeException( 'Cannot unserialize singleton.' );
	}
}
