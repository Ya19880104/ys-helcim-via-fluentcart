<?php
/**
 * YS HelcimPay Gateway — FluentCart payment method (slug: ys_helcim).
 *
 * HelcimPay.js modal checkout flow (Paddle-style custom checkout button):
 * custom button → orderHandler() creates the order → makePaymentFromPaymentInstance
 * calls Helcim initialize → the front end runs appendHelcimPayIframe → postMessage
 * SUCCESS → confirm AJAX (fail-closed validation) → payment succeeds.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\HelcimPay;

use FluentCart\Api\CurrencySettings;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Services\Payments\PaymentInstance;
use YangSheep\Helcim\FluentCart\Settings\YSHelcimSecretStorage;
use YangSheep\Helcim\FluentCart\Support\YSHelcimLogger;
use YangSheep\Helcim\FluentCart\Webhook\YSHelcimWebhookDeliveryUrl;
use YangSheep\Helcim\FluentCart\YSHelcimFctBootstrap;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HelcimPay.js modal gateway.
 */
class YSHelcimPayGateway extends AbstractPaymentGateway {

	/**
	 * Supported features ('refund' is what enables the admin refund button; subscriptions are not supported — v1 decision D9).
	 *
	 * @var array
	 */
	public array $supportedFeatures = array( 'payment', 'webhook', 'custom_payment' );

	/**
	 * Flow processor (lazily created).
	 *
	 * @var YSHelcimPayProcessor|null
	 */
	private $processorInstance = null;

	/**
	 * Constructor: inject the settings and declare the custom checkout button.
	 */
	public function __construct() {
		parent::__construct( new YSHelcimPaySettings() );

		// Paddle-style custom checkout button: the front end hides the native Place Order button and this gateway's JS renders its own.
		add_filter(
			'fluent_cart/payment_methods_with_custom_checkout_buttons',
			static function ( $methods ) {
				$methods[] = 'ys_helcim';
				return $methods;
			}
		);
	}

	/**
	 * Called when the GatewayManager registers the gateway: hook up the AJAX endpoints and the logger switch.
	 *
	 * @return void
	 */
	public function boot() {
		$processor = $this->processor();

		// Confirm AJAX (available to both logged-in and guest users).
		add_action( 'wp_ajax_ys_helcim_fct_confirm_pay', array( $processor, 'handleConfirmAjax' ) );
		add_action( 'wp_ajax_nopriv_ys_helcim_fct_confirm_pay', array( $processor, 'handleConfirmAjax' ) );

		// Debug mode only turns logging on, never off: this avoids overriding the other Helcim gateway's debug setting.
		if ( $this->settings->isDebugMode() ) {
			YSHelcimLogger::set_enabled( true );
		}
	}

	/**
	 * Get the flow processor (a lazy singleton per gateway).
	 *
	 * @return YSHelcimPayProcessor
	 */
	public function processor(): YSHelcimPayProcessor {
		if ( null === $this->processorInstance ) {
			$this->processorInstance = new YSHelcimPayProcessor( $this->settings );
		}
		return $this->processorInstance;
	}

	/**
	 * Gateway metadata.
	 *
	 * GatewayManager::getAllMeta validates the required keys:
	 * brand_color / description / icon / logo / route / status / title.
	 *
	 * @return array
	 */
	public function meta(): array {
		return array(
			'title'              => __( 'Credit card (Helcim)', 'ys-helcim-via-fluentcart' ),
			'route'              => 'ys_helcim',
			'slug'               => 'ys_helcim',
			'label'              => 'Helcim Pay',
			'admin_title'        => 'Helcim Pay (HelcimPay.js)',
			'description'        => __( 'Accept credit card payments through the secure HelcimPay.js checkout window (USD and CAD only).', 'ys-helcim-via-fluentcart' ),
			'logo'               => YS_HELCIM_FCT_URL . 'assets/images/helcim-logo.svg',
			'icon'               => YS_HELCIM_FCT_URL . 'assets/images/helcim-icon.svg',
			'brand_color'        => '#5B4FE9',
			'upcoming'           => false,
			'status'             => $this->isEnabled(),
			'supported_features' => $this->supportedFeatures,
		);
	}

	/** Keep invalid or unverifiable permanent credentials out of checkout. */
	public function isEnabled(): bool {
		return 'yes' === $this->settings->get( 'is_active' ) && $this->hasCompleteRecoveryCredentials();
	}

	// ── Currency guard ────────────────────────────────────────────────────────

	/**
	 * Get the currencies implemented by the payment contract.
	 *
	 * @return string[] Uppercase currency codes.
	 */
	public static function supportedCurrencies(): array {
		return array( 'USD', 'CAD' );
	}

	/**
	 * Whether the store currency is supported (when false, this payment method does not appear at checkout).
	 *
	 * @return bool
	 */
	public function isCurrencySupported(): bool {
		$store_currency = strtoupper( (string) CurrencySettings::get( 'currency' ) );

		return in_array( $store_currency, self::supportedCurrencies(), true );
	}

	// ── Order creation and payment ────────────────────────────────────────────

	/**
	 * Start the payment after the order is created (called by FluentCart's CheckoutApi).
	 *
	 * The returned array is sent to the front end as-is via wp_send_json; a
	 * WP_Error results in a 422 plus the error message.
	 *
	 * @param PaymentInstance $paymentInstance The payment instance.
	 * @return array|\WP_Error
	 */
	public function makePaymentFromPaymentInstance( PaymentInstance $paymentInstance ) {
		$transaction = $paymentInstance->transaction;

		if ( ! $transaction ) {
			return new \WP_Error(
				'ys_helcim_no_transaction',
				__( 'The transaction could not be found. Please refresh the page and try again.', 'ys-helcim-via-fluentcart' )
			);
		}

		// Currency guard (belt and braces: checkout already filters via isCurrencySupported; this protects against a direct API call).
		$currency = strtoupper( (string) $transaction->currency );
		if ( ! in_array( $currency, self::supportedCurrencies(), true ) ) {
			return new \WP_Error(
				'ys_helcim_currency_not_supported',
				sprintf(
					/* translators: %s: currency code */
					__( 'Helcim does not support the %s currency. Please use a different payment method.', 'ys-helcim-via-fluentcart' ),
					$currency
				)
			);
		}

		if ( ! $this->hasCompleteRecoveryCredentials() ) {
			YSHelcimLogger::error(
				'HelcimPay: recovery credentials are incomplete, cannot initialize payment',
				array( 'mode' => $this->settings->getMode() )
			);
			return new \WP_Error(
				'ys_helcim_missing_credentials',
				__( 'The Helcim credentials are incomplete. Please contact the site administrator.', 'ys-helcim-via-fluentcart' )
			);
		}
		if ( ! $this->hasDurableRecoverySchedule() ) {
			YSHelcimLogger::error( 'HelcimPay: durable hosted recovery schedule is unavailable' );
			return $this->recoveryUnavailableError();
		}
		$recovery_access = $this->verifyRecoveryApiAccess();
		if ( is_wp_error( $recovery_access ) ) {
			YSHelcimLogger::error( 'HelcimPay: card-transaction recovery permission is unavailable' );
			return $recovery_access;
		}

		return $this->processor()->initialize( $paymentInstance );
	}

	// ── Checkout info (no secrets) ─────────────────────────────────────────────

	/**
	 * Checkout payment-info endpoint (called when the front end fetches paymentInfoUrl).
	 *
	 * Returns only the public data needed to render the button — never any secrets.
	 *
	 * @param array $data The request data.
	 * @return void Always ends with wp_send_json.
	 */
	public function getOrderInfo( array $data ) {
		if ( ! $this->hasCompleteRecoveryCredentials() ) {
			wp_send_json(
				array(
					'status'  => 'failed',
					'message' => __( 'The Helcim payment method is not fully configured. Please contact the site administrator.', 'ys-helcim-via-fluentcart' ),
				),
				503
			);
		}
		if ( ! $this->hasDurableRecoverySchedule() ) {
			$error = $this->recoveryUnavailableError();
			wp_send_json(
				array(
					'status'  => 'failed',
					'message' => $error->get_error_message(),
				),
				503
			);
		}
		$recovery_access = $this->verifyRecoveryApiAccess();
		if ( is_wp_error( $recovery_access ) ) {
			wp_send_json(
				array(
					'status'  => 'failed',
					'message' => $recovery_access->get_error_message(),
				),
				503
			);
		}

		wp_send_json(
			array(
				'status'       => 'success',
				'message'      => __( 'Payment information retrieved.', 'ys-helcim-via-fluentcart' ),
				'payment_args' => array(
					'mode'        => $this->settings->getMode(),
					'currency_ok' => $this->isCurrencySupported(),
					'button_text' => $this->settings->getCheckoutButtonText(),
				),
			),
			200
		);
	}

	/** Require the charge token and signed-webhook recovery token for the current store mode. */
	private function hasCompleteRecoveryCredentials(): bool {
		return '' !== trim( $this->settings->getApiToken() )
			&& '' !== trim( $this->settings->getWebhookVerifierToken() );
	}

	/** Hosted checkout must never start without its durable lost-callback worker. */
	protected function hasDurableRecoverySchedule(): bool {
		return YSHelcimFctBootstrap::init()->ensureHostedPurchaseReconciliation();
	}

	/** @return true|\WP_Error */
	protected function verifyRecoveryApiAccess() {
		return ( new YSHelcimPayRecoveryCapability() )->verify( $this->settings );
	}

	private function recoveryUnavailableError(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_hosted_recovery_unavailable',
			__( 'Hosted payment recovery is unavailable. Please contact the site administrator.', 'ys-helcim-via-fluentcart' )
		);
	}

	// ── Front-end assets ──────────────────────────────────────────────────────

	/**
	 * Scripts to load on the checkout page (the HelcimPay SDK plus this plugin's checkout flow).
	 *
	 * @param string $hasSubscription Whether the cart contains a subscription product ('yes' / 'no').
	 * @return array
	 */
	public function getEnqueueScriptSrc( $hasSubscription = 'no' ): array {
		return array(
			array(
				'handle' => 'ys-helcim-pay-sdk',
				'src'    => 'https://secure.helcim.app/helcim-pay/services/start.js',
			),
			array(
				'handle' => 'ys-helcim-pay-checkout',
				'src'    => YS_HELCIM_FCT_URL . 'assets/js/ys-helcim-pay-checkout.js',
				'deps'   => array( 'ys-helcim-pay-sdk' ),
			),
		);
	}

	/**
	 * Styles to load on the checkout page.
	 *
	 * @return array
	 */
	public function getEnqueueStyleSrc(): array {
		return array(
			array(
				'handle' => 'ys-helcim-checkout',
				'src'    => YS_HELCIM_FCT_URL . 'assets/css/ys-helcim-checkout.css',
			),
		);
	}

	/**
	 * Front-end asset version (busts the cache with each plugin release).
	 *
	 * @return string
	 */
	public function getEnqueueVersion() {
		return YS_HELCIM_FCT_VERSION;
	}

	/**
	 * Front-end localize data (cross-lane integration contract: variable name ys_helcim_fct_data).
	 *
	 * @return array
	 */
	public function getLocalizeData(): array {
		return array(
			'ys_helcim_fct_data' => array(
				'ajax_url'       => admin_url( 'admin-ajax.php' ),
				'confirm_action' => 'ys_helcim_fct_confirm_pay',
				'translations'   => array(
					'button_text'    => $this->settings->getCheckoutButtonText(),
					'loading'        => __( 'Loading payment module…', 'ys-helcim-via-fluentcart' ),
					'confirming'     => __( 'Confirming your payment…', 'ys-helcim-via-fluentcart' ),
					'redirecting'    => __( 'Payment successful! Taking you to the order confirmation…', 'ys-helcim-via-fluentcart' ),
					'confirm_failed' => __( 'We could not confirm your payment. Please contact the site administrator.', 'ys-helcim-via-fluentcart' ),
					'init_failed'    => __( 'The payment window could not load. Please refresh the page and try again.', 'ys-helcim-via-fluentcart' ),
					'network_error'  => __( 'The payment result could not be confirmed. To prevent a duplicate charge, refresh the page or contact the store before trying again.', 'ys-helcim-via-fluentcart' ),
					'order_failed'   => __( 'The order could not be created. Please check your checkout details and try again.', 'ys-helcim-via-fluentcart' ),
					'sdk_missing'    => __( 'The payment component has not loaded. Please refresh the page and try again.', 'ys-helcim-via-fluentcart' ),
					'uncertain'      => __( 'The payment window closed before its result could be confirmed. To prevent a duplicate charge, refresh the page or contact the store before trying again.', 'ys-helcim-via-fluentcart' ),
				),
			),
		);
	}

	// ── Refund ────────────────────────────────────────────────────────────────

	/**
	 * Fail-closed guard for FluentCart's unsafe local-first refund flow.
	 *
	 * @param OrderTransaction $transaction The original charge transaction.
	 * @param int              $amount      The refund amount (in cents).
	 * @param array            $args        Additional arguments (reason, etc.).
	 * @return \WP_Error Always directs administrators to the remote-first flow.
	 */
	public function processRefund( $transaction, $amount, $args ) {
		unset( $transaction, $amount, $args );

		return new \WP_Error(
			'ys_helcim_native_refund_disabled',
			__( 'Use the Helcim Operations refund panel. FluentCart\'s native refund flow is disabled because it records a refund before the provider confirms it.', 'ys-helcim-via-fluentcart' )
		);
	}

	// ── Webhook (IPN) ─────────────────────────────────────────────────────────

	/**
	 * Retired FluentCart IPN compatibility route.
	 *
	 * Verification and reconciliation belong exclusively to the clean REST route.
	 * This handler performs no provider lookup and no local payment mutation.
	 *
	 * @return void Always ends with wp_send_json.
	 */
	public function handleIPN(): void {
		wp_send_json(
			array(
				'message'     => __( 'This legacy webhook listener is retired. Configure Helcim to use the REST webhook URL.', 'ys-helcim-via-fluentcart' ),
				'webhook_url' => rest_url( 'ys-fc-pay/v1/events/card' ),
			),
			410
		);
	}

	// ── Admin settings ────────────────────────────────────────────────────────

	/**
	 * Admin settings fields.
	 *
	 * Secret fields (password type) skip sanitization via FluentCart's
	 * Helper::sanitize and are encrypted in beforeSettingsUpdate before storage.
	 *
	 * @return array
	 */
	public function fields(): array {
		$webhook_url = YSHelcimWebhookDeliveryUrl::validate( rest_url( 'ys-fc-pay/v1/events/card' ) );

		$notice_html = $this->renderStoreModeNotice()
			. '<div class="mt-5"><p>'
			. esc_html__( 'Helcim has no separate sandbox environment: to test, request a "developer test account" from Helcim and use that account\'s API token together with the official test card numbers.', 'ys-helcim-via-fluentcart' )
			. '</p><p>'
			. esc_html__( 'Currency restriction: Helcim supports only USD and CAD. When the store currency does not match, this payment method is hidden at checkout.', 'ys-helcim-via-fluentcart' )
			. '</p></div>';

		$webhook_html = is_wp_error( $webhook_url )
			? '<div class="notice notice-error inline"><p>' . esc_html( $webhook_url->get_error_message() ) . '</p></div>'
			: sprintf(
			'<div><p><b>%1$s</b><code class="copyable-content">%2$s</code></p><p>%3$s</p><p>%4$s</p></div>',
			esc_html__( 'Webhook URL:', 'ys-helcim-via-fluentcart' ),
			esc_url( $webhook_url ),
			esc_html__( 'In your Helcim dashboard, go to All Tools → Integrations → Webhooks and set the URL above (HTTPS only), then paste that account\'s Verifier Token into the matching credential tab.', 'ys-helcim-via-fluentcart' ),
			esc_html__( 'The matching Webhook Verifier Token is required before this payment method can be enabled, because signed webhook recovery resolves lost browser confirmations safely.', 'ys-helcim-via-fluentcart' )
			);

		return array(
			'notice'                 => array(
				'value' => $notice_html,
				'label' => __( 'Store mode notice', 'ys-helcim-via-fluentcart' ),
				'type'  => 'notice',
			),
			'payment_mode'           => array(
				'type'   => 'tabs',
				'schema' => array(
					array(
						'type'   => 'tab',
						'label'  => __( 'Live credentials', 'ys-helcim-via-fluentcart' ),
						'value'  => 'live',
						'schema' => array(
							'live_api_token' => array(
								'value'       => '',
								'label'       => __( 'Live API Token', 'ys-helcim-via-fluentcart' ),
								'type'        => 'password',
								'placeholder' => __( 'Helcim API token for your live account', 'ys-helcim-via-fluentcart' ),
								'dependency'  => array(
									'depends_on' => 'payment_mode',
									'operator'   => '=',
									'value'      => 'live',
								),
							),
							'live_webhook_verifier_token' => array(
								'value'       => '',
								'label'       => __( 'Live Webhook Verifier Token', 'ys-helcim-via-fluentcart' ),
								'type'        => 'password',
								'placeholder' => __( 'Verifier Token for the live Helcim webhook', 'ys-helcim-via-fluentcart' ),
								'dependency'  => array(
									'depends_on' => 'payment_mode',
									'operator'   => '=',
									'value'      => 'live',
								),
							),
						),
					),
					array(
						'type'   => 'tab',
						'label'  => __( 'Test credentials', 'ys-helcim-via-fluentcart' ),
						'value'  => 'test',
						'schema' => array(
							'test_api_token' => array(
								'value'       => '',
								'label'       => __( 'Test API Token', 'ys-helcim-via-fluentcart' ),
								'type'        => 'password',
								'placeholder' => __( 'Helcim API token for your developer test account', 'ys-helcim-via-fluentcart' ),
								'dependency'  => array(
									'depends_on' => 'payment_mode',
									'operator'   => '=',
									'value'      => 'test',
								),
							),
							'test_webhook_verifier_token' => array(
								'value'       => '',
								'label'       => __( 'Test Webhook Verifier Token', 'ys-helcim-via-fluentcart' ),
								'type'        => 'password',
								'placeholder' => __( 'Verifier Token for the developer test account webhook', 'ys-helcim-via-fluentcart' ),
								'dependency'  => array(
									'depends_on' => 'payment_mode',
									'operator'   => '=',
									'value'      => 'test',
								),
							),
						),
					),
				),
			),
			'webhook_desc'           => array(
				'value' => $webhook_html,
				'label' => __( 'Webhook URL', 'ys-helcim-via-fluentcart' ),
				'type'  => 'html_attr',
			),
			'checkout_button_text'   => array(
				'value'       => '',
				'label'       => __( 'Checkout button text', 'ys-helcim-via-fluentcart' ),
				'type'        => 'text',
				'placeholder' => __( 'Pay with credit card (Helcim)', 'ys-helcim-via-fluentcart' ),
			),
			'debug_mode'             => array(
				'value'   => 'no',
				'label'   => __( 'Debug logging', 'ys-helcim-via-fluentcart' ),
				'type'    => 'radio',
				'options' => array(
					'no'  => array(
						'label' => __( 'Disabled', 'ys-helcim-via-fluentcart' ),
						'text'  => __( 'Log errors only (recommended for production).', 'ys-helcim-via-fluentcart' ),
					),
					'yes' => array(
						'label' => __( 'Enabled', 'ys-helcim-via-fluentcart' ),
						'text'  => __( 'Log the full API request and response (sensitive data is masked automatically).', 'ys-helcim-via-fluentcart' ),
					),
				),
			),
		);
	}

	/**
	 * Validate the complete charge and recovery pair for the current store mode.
	 *
	 * @param array $data The submitted settings.
	 * @return array {status, message}
	 */
	public static function validateSettings( $data ): array {
		$mode       = 'test' === ( new StoreSettings() )->get( 'order_mode' ) ? 'test' : 'live';
		$mode_label = 'test' === $mode
			? __( 'test', 'ys-helcim-via-fluentcart' )
			: __( 'live', 'ys-helcim-via-fluentcart' );
		$settings = new YSHelcimPaySettings();
		$api_token_available = YSHelcimSecretStorage::isAvailableForValidation(
			array_key_exists( $mode . '_api_token', (array) $data ) ? $data[ $mode . '_api_token' ] : null,
			$settings->getApiTokenForMode( $mode )
		);
		$verifier_available = YSHelcimSecretStorage::isAvailableForValidation(
			array_key_exists( $mode . '_webhook_verifier_token', (array) $data ) ? $data[ $mode . '_webhook_verifier_token' ] : null,
			$settings->getWebhookVerifierTokenForMode( $mode )
		);

		if ( ! $api_token_available ) {
			return array(
				'status'  => 'failed',
				/* translators: %s: mode name (test/live) */
				'message' => sprintf( __( 'Please enter the API token for %s mode.', 'ys-helcim-via-fluentcart' ), $mode_label ),
			);
		}
		if ( ! $verifier_available ) {
			return array(
				'status'  => 'failed',
				/* translators: %s: mode name (test/live) */
				'message' => sprintf( __( 'Please enter the Webhook Verifier Token for %s mode.', 'ys-helcim-via-fluentcart' ), $mode_label ),
			);
		}

		return array(
			'status'  => 'success',
			'message' => __( 'Settings saved.', 'ys-helcim-via-fluentcart' ),
		);
	}

	/**
	 * Process before saving settings: persist only secrets proven to be ciphertext.
	 *
	 * Existing values are accepted only when FluentCart proves valid ciphertext.
	 * Blank fields preserve verified old ciphertext. A new value that cannot be
	 * encrypted and independently verified is discarded and disables the gateway.
	 *
	 * @param array $data        The new settings.
	 * @param array $oldSettings The previous settings.
	 * @return array
	 */
	public static function beforeSettingsUpdate( $data, $oldSettings ): array {
		$persistence_failed = false;
		foreach ( array(
			'test_api_token',
			'live_api_token',
			'test_webhook_verifier_token',
			'live_webhook_verifier_token',
		) as $key ) {
			$data[ $key ] = YSHelcimSecretStorage::prepareForStorage(
				array_key_exists( $key, (array) $data ) ? $data[ $key ] : null,
				array_key_exists( $key, (array) $oldSettings ) ? $oldSettings[ $key ] : null,
				$persistence_failed
			);
		}

		if ( $persistence_failed ) {
			$data['is_active'] = 'no';
		}

		// Legacy values are bound once during bootstrap migration. Settings
		// saves may clear stale input, but must never reinterpret its mode.
		$data['webhook_verifier_token'] = '';

		return $data;
	}

	// ── Internal helpers ──────────────────────────────────────────────────────

	/**
	 * Get the server IP (a required ipAddress field for the Helcim refund API).
	 *
	 * @return string
	 */
	private static function getServerIp(): string {
		$server_addr = isset( $_SERVER['SERVER_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) ) : '';

		if ( '' !== $server_addr && filter_var( $server_addr, FILTER_VALIDATE_IP ) ) {
			return $server_addr;
		}

		$hostname = gethostname();
		if ( false !== $hostname ) {
			$resolved = gethostbyname( $hostname );
			if ( filter_var( $resolved, FILTER_VALIDATE_IP ) ) {
				return $resolved;
			}
		}

		return '127.0.0.1';
	}

}
