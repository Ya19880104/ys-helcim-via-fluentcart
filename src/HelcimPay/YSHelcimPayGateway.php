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
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Services\Payments\PaymentInstance;
use YangSheep\Helcim\FluentCart\Support\YSHelcimApiClient;
use YangSheep\Helcim\FluentCart\Support\YSHelcimLogger;

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
	public array $supportedFeatures = array( 'payment', 'refund', 'webhook', 'custom_payment' );

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
			'status'             => $this->settings->get( 'is_active' ) === 'yes',
			'supported_features' => $this->supportedFeatures,
		);
	}

	// ── Currency guard ────────────────────────────────────────────────────────

	/**
	 * Get the list of supported currencies (extendable via filter).
	 *
	 * @return string[] Uppercase currency codes.
	 */
	public static function supportedCurrencies(): array {
		/**
		 * Filter the currencies Helcim supports (defaults to USD / CAD).
		 *
		 * @param string[] $currencies List of currency codes.
		 */
		$currencies = apply_filters( 'ys_helcim_fct_supported_currencies', array( 'USD', 'CAD' ) );

		// If the filter returns an empty or non-array value, fall back to the defaults so we never end up with "no currency is supported".
		if ( ! is_array( $currencies ) || empty( $currencies ) ) {
			$currencies = array( 'USD', 'CAD' );
		}

		return array_map( 'strtoupper', array_map( 'strval', $currencies ) );
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
					'pay_button'       => $this->settings->getCheckoutButtonText(),
					'processing'       => __( 'Processing payment…', 'ys-helcim-via-fluentcart' ),
					'confirming'       => __( 'Confirming your payment…', 'ys-helcim-via-fluentcart' ),
					'redirecting'      => __( 'Payment successful! Taking you to the order confirmation…', 'ys-helcim-via-fluentcart' ),
					'payment_canceled' => __( 'Payment canceled. You can try again.', 'ys-helcim-via-fluentcart' ),
					'payment_failed'   => __( 'Payment failed. Please try again shortly or use a different payment method.', 'ys-helcim-via-fluentcart' ),
					'confirm_failed'   => __( 'We could not confirm your payment. Please contact the site administrator.', 'ys-helcim-via-fluentcart' ),
					'init_failed'      => __( 'The payment window could not load. Please refresh the page and try again.', 'ys-helcim-via-fluentcart' ),
					'network_error'    => __( 'A network error occurred. Please try again shortly.', 'ys-helcim-via-fluentcart' ),
					'order_failed'     => __( 'The order could not be created. Please check your checkout details and try again.', 'ys-helcim-via-fluentcart' ),
				),
			),
		);
	}

	// ── Refund ────────────────────────────────────────────────────────────────

	/**
	 * Remote refund (called by FluentCart's admin refund flow).
	 *
	 * Contract (Refund service): $amount is in cents; on success returns the
	 * vendor refund id string, on failure returns a WP_Error. An idempotency key
	 * is always sent to guard against duplicate refunds.
	 *
	 * @param OrderTransaction $transaction The original charge transaction.
	 * @param int              $amount      The refund amount (in cents).
	 * @param array            $args        Additional arguments (reason, etc.).
	 * @return string|\WP_Error The refund transaction ID, or an error.
	 */
	public function processRefund( $transaction, $amount, $args ) {
		$amount = (int) $amount;
		if ( $amount <= 0 ) {
			return new \WP_Error(
				'ys_helcim_refund_error',
				__( 'The refund amount must be greater than zero.', 'ys-helcim-via-fluentcart' )
			);
		}

		$original_transaction_id = (int) $transaction->vendor_charge_id;
		if ( $original_transaction_id <= 0 ) {
			return new \WP_Error(
				'ys_helcim_refund_error',
				__( 'This transaction has no Helcim transaction ID, so it cannot be refunded online.', 'ys-helcim-via-fluentcart' )
			);
		}

		// Mode guard: prevents refunding a test transaction with a live token (or vice versa) — consistent with ys_helcim_js.
		$transaction_mode = (string) $transaction->payment_mode;
		if ( '' !== $transaction_mode && $transaction_mode !== $this->settings->getMode() ) {
			return new \WP_Error(
				'ys_helcim_refund_error',
				__( 'The transaction mode does not match the current store mode. Please switch the store order mode before refunding.', 'ys-helcim-via-fluentcart' )
			);
		}

		$api_token = $this->settings->getApiToken();
		if ( '' === $api_token ) {
			return new \WP_Error(
				'ys_helcim_refund_error',
				__( 'The Helcim API token has not been configured, so refunds are not possible.', 'ys-helcim-via-fluentcart' )
			);
		}

		$payload = array(
			'originalTransactionId' => $original_transaction_id,
			'amount'                => number_format( $amount / 100, 2, '.', '' ),
			'ipAddress'             => self::getServerIp(),
		);

		// Idempotency key (deterministic, security review M2): bound to the original transaction + amount + existing refund count.
		// A "blind retry after a lost response" produces the same key (Helcim deduplicates naturally on its end);
		// a legitimate second partial refund of the same amount gets a new key because the previous one already wrote a refund transaction (count + 1).
		$refund_seq      = OrderTransaction::query()
			->where( 'order_id', $transaction->order_id )
			->where( 'transaction_type', Status::TRANSACTION_TYPE_REFUND )
			->count();
		$idempotency_key = substr( 'yshfct-rf-' . $original_transaction_id . '-' . $amount . '-' . $refund_seq, 0, 36 );

		$response = YSHelcimApiClient::request( 'payment/refund', $payload, $api_token, $idempotency_key );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'ys_helcim_refund_error',
				sprintf(
					/* translators: %s: the error message returned by Helcim */
					__( 'Helcim refund failed: %s', 'ys-helcim-via-fluentcart' ),
					$response->get_error_message()
				)
			);
		}

		if ( 'APPROVED' !== strtoupper( (string) ( $response['status'] ?? '' ) ) ) {
			YSHelcimLogger::error(
				'Refund not approved',
				array(
					'transaction_uuid' => $transaction->uuid,
					'response_status'  => (string) ( $response['status'] ?? '' ),
				)
			);
			return new \WP_Error(
				'ys_helcim_refund_error',
				__( 'Helcim did not approve this refund. Please check your Helcim dashboard.', 'ys-helcim-via-fluentcart' )
			);
		}

		YSHelcimLogger::info(
			'Refund approved',
			array(
				'transaction_uuid' => $transaction->uuid,
				'refund_id'        => (string) ( $response['transactionId'] ?? '' ),
			)
		);

		return (string) ( $response['transactionId'] ?? '' );
	}

	// ── Webhook (IPN) ─────────────────────────────────────────────────────────

	/**
	 * Webhook handler (FluentCart route: ?fluent-cart=fct_payment_listener_ipn&method=ys_helcim).
	 *
	 * Flow: verify the signature (via the shared Verifier, built in another lane)
	 * → type=cardTransaction → GET card-transactions/{id} to confirm → mark any
	 * pending transaction as paid. Always emits an appropriate HTTP code and
	 * exits (wp_send_json includes a die).
	 *
	 * @return void
	 */
	public function handleIPN(): void {
		$raw_body = file_get_contents( 'php://input' );

		// Resource guard before verification: a Helcim webhook body is tiny, so reject anything over 1 MB outright.
		if ( strlen( (string) $raw_body ) > 1048576 ) {
			wp_send_json( array( 'message' => 'payload too large' ), 400 );
		}

		$headers = self::getRequestHeaders();

		// No verifier token set → the feature is not enabled (log it and return 501).
		$verifier_token = $this->settings->getWebhookVerifierToken();
		if ( '' === $verifier_token ) {
			YSHelcimLogger::error( 'Webhook received but verifier token is not configured' );
			wp_send_json( array( 'message' => __( 'The webhook verifier token has not been configured.', 'ys-helcim-via-fluentcart' ) ), 501 );
		}

		// The verifier is built in another lane — guard with class_exists.
		$verifier_class = '\\YangSheep\\Helcim\\FluentCart\\Webhook\\YSHelcimWebhookVerifier';
		if ( ! class_exists( $verifier_class ) ) {
			YSHelcimLogger::error( 'Webhook verifier class is missing' );
			wp_send_json( array( 'message' => __( 'The webhook verification component is missing.', 'ys-helcim-via-fluentcart' ) ), 501 );
		}

		// Signature verification failed → 401 (fail-closed).
		if ( ! $verifier_class::verify( $headers, (string) $raw_body, $verifier_token ) ) {
			YSHelcimLogger::error( 'Webhook signature verification failed' );
			wp_send_json( array( 'message' => __( 'Webhook signature verification failed.', 'ys-helcim-via-fluentcart' ) ), 401 );
		}

		$payload = json_decode( (string) $raw_body, true );
		if ( ! is_array( $payload ) ) {
			wp_send_json( array( 'message' => __( 'The webhook payload is malformed.', 'ys-helcim-via-fluentcart' ) ), 400 );
		}

		// Only handle cardTransaction events; acknowledge any other type outright.
		if ( 'cardTransaction' !== (string) ( $payload['type'] ?? '' ) ) {
			wp_send_json( array( 'message' => 'ignored' ), 200 );
		}

		// Helcim transaction IDs are digits only — whitelist filter (consistent with ys_helcim_js).
		$helcim_tx_id = preg_replace( '/[^0-9]/', '', (string) ( $payload['id'] ?? '' ) );
		if ( '' === $helcim_tx_id ) {
			wp_send_json( array( 'message' => __( 'The webhook is missing a transaction ID.', 'ys-helcim-via-fluentcart' ) ), 400 );
		}

		$this->reconcileCardTransaction( $helcim_tx_id );
	}

	/**
	 * Webhook reconciliation: verify the Helcim transaction and confirm any pending transaction.
	 *
	 * @param string $helcim_tx_id The Helcim transaction ID.
	 * @return void Always ends with wp_send_json.
	 */
	private function reconcileCardTransaction( string $helcim_tx_id ): void {
		$api_token = $this->settings->getApiToken();
		if ( '' === $api_token ) {
			YSHelcimLogger::error( 'Webhook reconcile skipped: api token missing' );
			wp_send_json( array( 'message' => __( 'The API token has not been configured.', 'ys-helcim-via-fluentcart' ) ), 501 );
		}

		// Verify the transaction via the API (do not trust the webhook payload itself).
		$tx = YSHelcimApiClient::request( 'card-transactions/' . rawurlencode( $helcim_tx_id ), array(), $api_token, null, 'GET' );

		if ( is_wp_error( $tx ) ) {
			// Upstream verification failed → return 502 so Helcim retries.
			YSHelcimLogger::error(
				'Webhook reconcile: card-transaction lookup failed',
				array( 'helcim_tx_id' => $helcim_tx_id )
			);
			wp_send_json( array( 'message' => __( 'Transaction verification failed.', 'ys-helcim-via-fluentcart' ) ), 502 );
		}

		// Some responses wrap the payload inside data — unwrap defensively.
		if ( isset( $tx['data'] ) && is_array( $tx['data'] ) ) {
			$tx = $tx['data'];
		}

		// Only confirm an "approved purchase".
		if ( 'APPROVED' !== strtoupper( (string) ( $tx['status'] ?? '' ) ) || 'purchase' !== strtolower( (string) ( $tx['type'] ?? '' ) ) ) {
			wp_send_json( array( 'message' => 'ignored (not an approved purchase)' ), 200 );
		}

		$transaction = $this->findTransactionForWebhook( $tx, $helcim_tx_id );

		if ( ! $transaction ) {
			// No matching transaction (possibly not one of this gateway's) → acknowledge to avoid a retry storm.
			YSHelcimLogger::info(
				'Webhook reconcile: no matching transaction',
				array( 'helcim_tx_id' => $helcim_tx_id )
			);
			wp_send_json( array( 'message' => 'no matching transaction' ), 200 );
		}

		// Idempotent: already succeeded → acknowledge.
		if ( Status::TRANSACTION_SUCCEEDED === $transaction->status ) {
			wp_send_json( array( 'message' => 'already processed' ), 200 );
		}

		// Amount / currency check (fail-closed: if they do not match, do not record payment; return 400 so the admin can trace it in the log).
		$paid_cents = (int) round( ( (float) ( $tx['amount'] ?? 0 ) ) * 100 );
		if ( $paid_cents !== (int) $transaction->total || strtoupper( (string) ( $tx['currency'] ?? '' ) ) !== strtoupper( (string) $transaction->currency ) ) {
			YSHelcimLogger::error(
				'Webhook reconcile: amount/currency mismatch — possible tampering',
				array(
					'helcim_tx_id'     => $helcim_tx_id,
					'transaction_uuid' => $transaction->uuid,
					'expected_cents'   => (int) $transaction->total,
					'received_cents'   => $paid_cents,
				)
			);
			wp_send_json( array( 'message' => __( 'The transaction amount or currency does not match.', 'ys-helcim-via-fluentcart' ) ), 400 );
		}

		$marked = $this->processor()->markPaid( $transaction, $tx );

		if ( ! $marked ) {
			wp_send_json( array( 'message' => __( 'Failed to update the order status.', 'ys-helcim-via-fluentcart' ) ), 500 );
		}

		YSHelcimLogger::info(
			'Webhook reconcile: transaction confirmed',
			array(
				'helcim_tx_id'     => $helcim_tx_id,
				'transaction_uuid' => $transaction->uuid,
			)
		);
		wp_send_json( array( 'message' => 'ok' ), 200 );
	}

	/**
	 * Find this gateway's charge transaction from the Helcim transaction data.
	 *
	 * Matches first on invoiceNumber (= the order uuid, written during initialize);
	 * if that fails, matches on vendor_charge_id (the re-delivery case).
	 *
	 * @param array  $tx           The Helcim card-transaction response.
	 * @param string $helcim_tx_id The Helcim transaction ID.
	 * @return OrderTransaction|null
	 */
	private function findTransactionForWebhook( array $tx, string $helcim_tx_id ) {
		// Path 1: invoiceNumber = order uuid.
		$invoice_number = (string) ( $tx['invoiceNumber'] ?? '' );
		if ( '' !== $invoice_number ) {
			$order = Order::query()->where( 'uuid', $invoice_number )->first();
			if ( $order ) {
				$transaction = OrderTransaction::query()
					->where( 'order_id', $order->id )
					->where( 'payment_method', YSHelcimPayProcessor::GATEWAY_SLUG )
					->where( 'transaction_type', Status::TRANSACTION_TYPE_CHARGE )
					->orderBy( 'id', 'desc' )
					->first();
				if ( $transaction ) {
					return $transaction;
				}
			}
		}

		// Path 2: vendor_charge_id (when the transaction was confirmed once already and the webhook is re-delivered).
		return OrderTransaction::query()
			->where( 'vendor_charge_id', $helcim_tx_id )
			->where( 'payment_method', YSHelcimPayProcessor::GATEWAY_SLUG )
			->where( 'transaction_type', Status::TRANSACTION_TYPE_CHARGE )
			->first();
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
		$webhook_url = trailingslashit( site_url() ) . '?fluent-cart=fct_payment_listener_ipn&method=ys_helcim';

		$notice_html = $this->renderStoreModeNotice()
			. '<div class="mt-5"><p>'
			. esc_html__( 'Helcim has no separate sandbox environment: to test, request a "developer test account" from Helcim and use that account\'s API token together with the official test card numbers.', 'ys-helcim-via-fluentcart' )
			. '</p><p>'
			. esc_html__( 'Currency restriction: Helcim supports only USD and CAD. When the store currency does not match, this payment method is hidden at checkout.', 'ys-helcim-via-fluentcart' )
			. '</p></div>';

		$webhook_html = sprintf(
			'<div><p><b>%1$s</b><code class="copyable-content">%2$s</code></p><p>%3$s</p><p>%4$s</p></div>',
			esc_html__( 'Webhook URL:', 'ys-helcim-via-fluentcart' ),
			esc_url( $webhook_url ),
			esc_html__( 'In your Helcim dashboard, go to All Tools → Integrations → Webhooks and set the URL above (HTTPS only), then paste the Verifier Token into the field below.', 'ys-helcim-via-fluentcart' ),
			esc_html__( 'The webhook is used to reconcile and confirm payments — payments still work normally when it is not configured.', 'ys-helcim-via-fluentcart' )
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
						),
					),
				),
			),
			'webhook_desc'           => array(
				'value' => $webhook_html,
				'label' => __( 'Webhook URL', 'ys-helcim-via-fluentcart' ),
				'type'  => 'html_attr',
			),
			'webhook_verifier_token' => array(
				'value'       => '',
				'label'       => __( 'Webhook Verifier Token', 'ys-helcim-via-fluentcart' ),
				'type'        => 'password',
				'placeholder' => __( 'The Verifier Token from the Helcim webhook settings page', 'ys-helcim-via-fluentcart' ),
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
	 * Validate before saving settings: when enabled, the API token for the current store mode is required.
	 *
	 * @param array $data The submitted settings.
	 * @return array {status, message}
	 */
	public static function validateSettings( $data ): array {
		$mode      = ( new StoreSettings() )->get( 'order_mode' );
		$token_key = 'test' === $mode ? 'test_api_token' : 'live_api_token';

		if ( empty( $data[ $token_key ] ) ) {
			return array(
				'status'  => 'failed',
				'message' => 'test' === $mode
					? __( 'The store is currently in test mode. Please enter the test API token.', 'ys-helcim-via-fluentcart' )
					: __( 'The store is currently in live mode. Please enter the live API token.', 'ys-helcim-via-fluentcart' ),
			);
		}

		return array(
			'status'  => 'success',
			'message' => __( 'Settings saved.', 'ys-helcim-via-fluentcart' ),
		);
	}

	/**
	 * Process before saving settings: encrypt secret fields (only when the value has changed, matching the Stripe approach).
	 *
	 * Existing values returned from the admin form are already ciphertext — when
	 * unchanged they are sent back as-is and not re-encrypted (Helper::encryptKey
	 * also has isValueEncrypted as a second safeguard).
	 *
	 * @param array $data        The new settings.
	 * @param array $oldSettings The previous settings.
	 * @return array
	 */
	public static function beforeSettingsUpdate( $data, $oldSettings ): array {
		$secret_keys = array( 'live_api_token', 'test_api_token', 'webhook_verifier_token' );

		foreach ( $secret_keys as $key ) {
			$new_value = isset( $data[ $key ] ) ? (string) $data[ $key ] : '';
			$old_value = isset( $oldSettings[ $key ] ) ? (string) $oldSettings[ $key ] : '';

			if ( '' !== $new_value && $new_value !== $old_value ) {
				$data[ $key ] = Helper::encryptKey( $new_value );
			}
		}

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

	/**
	 * Get this request's HTTP headers (keys lowercased, for the webhook Verifier).
	 *
	 * @return array<string, string>
	 */
	private static function getRequestHeaders(): array {
		$headers = array();

		if ( function_exists( 'getallheaders' ) ) {
			$raw = getallheaders();
			if ( is_array( $raw ) ) {
				foreach ( $raw as $name => $value ) {
					$headers[ strtolower( (string) $name ) ] = (string) $value;
				}
				return $headers;
			}
		}

		// Fallback for environments such as FPM: rebuild from the HTTP_ prefixed $_SERVER keys.
		foreach ( $_SERVER as $name => $value ) {
			if ( 0 === strpos( (string) $name, 'HTTP_' ) ) {
				$header_name             = strtolower( str_replace( '_', '-', substr( (string) $name, 5 ) ) );
				$headers[ $header_name ] = (string) $value;
			}
		}

		return $headers;
	}
}
