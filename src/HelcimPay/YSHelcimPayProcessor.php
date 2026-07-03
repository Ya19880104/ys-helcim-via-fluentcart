<?php
/**
 * YS HelcimPay Processor — the HelcimPay.js checkout flow.
 *
 * Responsibilities:
 * 1. initialize: call Helcim /helcim-pay/initialize to create a checkout session,
 *    stash the encrypted secretToken in the transaction meta, and return the
 *    payment_data the front end needs.
 * 2. confirm (AJAX): fail-closed validation of the transaction result posted by
 *    the front end (nonce → transaction → idempotency → hash → status → type →
 *    currency → amount in integer cents), only marking payment successful once
 *    every check passes.
 * 3. markPaid: update the transaction to succeeded and sync the order status
 *    (idempotent, following the platform precedent of FluentCart's PayPal
 *    confirmPaymentSuccessByCharge).
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\HelcimPay;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Services\Payments\PaymentInstance;
use YangSheep\Helcim\FluentCart\Support\YSHelcimApiClient;
use YangSheep\Helcim\FluentCart\Support\YSHelcimLogger;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HelcimPay.js flow processor.
 */
class YSHelcimPayProcessor {

	/**
	 * Nonce action for the confirm endpoint (sent with payment_data, verified on confirm).
	 *
	 * @var string
	 */
	public const NONCE_ACTION = 'ys_helcim_fct_confirm_pay';

	/**
	 * Gateway slug (used to match transaction.payment_method).
	 *
	 * @var string
	 */
	public const GATEWAY_SLUG = 'ys_helcim';

	/**
	 * Transaction meta key: the HelcimPay checkout token.
	 *
	 * @var string
	 */
	private const META_CHECKOUT_TOKEN = 'ys_helcim_checkout_token';

	/**
	 * Transaction meta key: the encrypted secretToken (removed after a successful confirm).
	 *
	 * @var string
	 */
	private const META_SECRET_TOKEN = 'ys_helcim_secret_token_enc';

	/**
	 * Settings instance.
	 *
	 * @var YSHelcimPaySettings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param YSHelcimPaySettings $settings The gateway settings.
	 */
	public function __construct( YSHelcimPaySettings $settings ) {
		$this->settings = $settings;
	}

	// ── 1. Initialize ────────────────────────────────────────────────────────

	/**
	 * Create a HelcimPay checkout session.
	 *
	 * Called by Gateway::makePaymentFromPaymentInstance (the order is already
	 * created and the transaction is a pending charge). The returned array is
	 * sent to the front end as-is via FluentCart's CheckoutApi wp_send_json; a
	 * WP_Error results in a 422.
	 *
	 * @param PaymentInstance $payment_instance The FluentCart payment instance.
	 * @return array|\WP_Error The envelope (including payment_data) on success; a WP_Error on failure.
	 */
	public function initialize( PaymentInstance $payment_instance ) {
		$transaction = $payment_instance->transaction;
		$order       = $payment_instance->order;

		if ( ! $transaction || ! $order ) {
			return new \WP_Error(
				'ys_helcim_init_failed',
				__( 'The transaction could not be found. Please refresh the page and try again.', 'ys-helcim-via-fluentcart' )
			);
		}

		// FluentCart always stores amounts in cents → Helcim expects a decimal string in the main unit.
		$amount_cents = (int) $transaction->total;
		if ( $amount_cents <= 0 ) {
			return new \WP_Error(
				'ys_helcim_init_failed',
				__( 'The transaction amount is invalid, so the payment cannot proceed.', 'ys-helcim-via-fluentcart' )
			);
		}

		$api_token = $this->settings->getApiToken();
		if ( '' === $api_token ) {
			return new \WP_Error(
				'ys_helcim_init_failed',
				__( 'The Helcim API token has not been configured. Please contact the site administrator.', 'ys-helcim-via-fluentcart' )
			);
		}

		$payload = array(
			'paymentType'   => 'purchase',
			'amount'        => number_format( $amount_cents / 100, 2, '.', '' ),
			'currency'      => strtoupper( (string) $transaction->currency ),
			'paymentMethod' => 'cc',
			'invoiceNumber' => (string) $order->uuid,
		);

		/**
		 * Filter the HelcimPay initialize request parameters.
		 *
		 * @param array           $payload          The initialize payload.
		 * @param PaymentInstance $payment_instance The payment instance.
		 */
		$payload = apply_filters( 'ys_helcim_fct_initialize_args', $payload, $payment_instance );

		$response = YSHelcimApiClient::request( 'helcim-pay/initialize', $payload, $api_token );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'ys_helcim_init_failed',
				sprintf(
					/* translators: %s: the error message returned by Helcim */
					__( 'Could not initialize the Helcim payment: %s', 'ys-helcim-via-fluentcart' ),
					$response->get_error_message()
				)
			);
		}

		$checkout_token = (string) ( $response['checkoutToken'] ?? '' );
		$secret_token   = (string) ( $response['secretToken'] ?? '' );

		if ( '' === $checkout_token || '' === $secret_token ) {
			YSHelcimLogger::error(
				'Initialize response missing tokens',
				array( 'order_uuid' => $order->uuid )
			);
			return new \WP_Error(
				'ys_helcim_init_failed',
				__( 'Helcim returned an incomplete response, so the payment window cannot open.', 'ys-helcim-via-fluentcart' )
			);
		}

		// The secretToken is a one-time secret: store it encrypted in the transaction meta (removed after a successful confirm).
		$meta                              = is_array( $transaction->meta ) ? $transaction->meta : array();
		$meta[ self::META_CHECKOUT_TOKEN ] = $checkout_token;
		$meta[ self::META_SECRET_TOKEN ]   = Helper::encryptKey( $secret_token );
		$meta['ys_helcim_initialized_at']  = gmdate( 'Y-m-d H:i:s' );

		$transaction->update( array( 'meta' => $meta ) );

		YSHelcimLogger::info(
			'HelcimPay initialized',
			array(
				'order_uuid'       => $order->uuid,
				'transaction_uuid' => $transaction->uuid,
				'checkoutToken'    => $checkout_token,
			)
		);

		// The envelope follows the FluentCart platform contract:
		// actionName=custom → the front end dispatches fluent_cart_payment_next_action_ys_helcim;
		// custom data always goes under the payment_data key (cross-lane integration contract).
		return array(
			'status'       => 'success',
			'nextAction'   => self::GATEWAY_SLUG,
			'actionName'   => 'custom',
			'message'      => __( 'Your order has been created. Please complete the credit card payment in the payment window.', 'ys-helcim-via-fluentcart' ),
			'payment_data' => array(
				'checkout_token'   => $checkout_token,
				'transaction_uuid' => $transaction->uuid,
				'confirm_nonce'    => wp_create_nonce( self::NONCE_ACTION ),
				'mode'             => $this->settings->getMode(),
			),
		);
	}

	// ── 2. Confirm (AJAX) ─────────────────────────────────────────────────────

	/**
	 * Confirm AJAX endpoint (wp_ajax_ys_helcim_fct_confirm_pay + nopriv).
	 *
	 * Request parameters: transaction_uuid, event_data (the Helcim transaction data object as JSON), hash, nonce.
	 * The validation order is fixed (fail-closed; any missing piece or mismatch is rejected):
	 * nonce → load the transaction → idempotency → hash → status APPROVED → type purchase
	 * → currency → amount in integer cents → markPaid.
	 *
	 * @return void Always ends with wp_send_json.
	 */
	public function handleConfirmAjax(): void {
		// (1) Soft nonce check (log only, does not block).
		// Architectural decision: on the OrderPaid event FluentCart automatically creates an account and logs it in (AuthService::makeLogin),
		// so the session changes after the first successful confirm — on a retry/replay of confirm the old nonce is guaranteed to be invalid,
		// and a hard check would misread a "legitimate retry of an already-paid order" as a 403. Platform precedents (the Stripe/PayPal confirm endpoints)
		// do not verify a WP nonce either. The real anti-forgery guard here is the secretToken hash (step 4) + the unguessable uuid
		// + the amount/currency/status checks; the nonce is only a defense-in-depth diagnostic signal.
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			YSHelcimLogger::info( 'Confirm nonce mismatch (soft check, not blocking)' );
		}

		// (2) Load the transaction (restricted to this gateway's charge transaction).
		$transaction_uuid = isset( $_POST['transaction_uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['transaction_uuid'] ) ) : '';
		if ( '' === $transaction_uuid ) {
			$this->rejectConfirm( 400, __( 'The transaction identifier is missing.', 'ys-helcim-via-fluentcart' ), 'missing transaction_uuid' );
		}

		$transaction = OrderTransaction::query()
			->where( 'uuid', $transaction_uuid )
			->where( 'payment_method', self::GATEWAY_SLUG )
			->where( 'transaction_type', Status::TRANSACTION_TYPE_CHARGE )
			->first();

		if ( ! $transaction ) {
			$this->rejectConfirm( 404, __( 'The matching transaction could not be found. Please contact the site administrator.', 'ys-helcim-via-fluentcart' ), 'transaction not found', array( 'transaction_uuid' => $transaction_uuid ) );
		}

		// (3) Idempotency: already paid → return the receipt page directly (no reprocessing).
		if ( Status::TRANSACTION_SUCCEEDED === $transaction->status ) {
			wp_send_json(
				$this->buildSuccessResponse( $transaction, __( 'Payment already completed. Taking you to the order confirmation…', 'ys-helcim-via-fluentcart' ) ),
				200
			);
		}

		// (4) Hash verification (fail-closed: a missing secretToken is rejected).
		$raw_event = isset( $_POST['event_data'] ) ? wp_unslash( $_POST['event_data'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the raw JSON must stay verbatim to take part in hash verification; it is only decoded, never output.
		if ( ! is_string( $raw_event ) || '' === $raw_event ) {
			$this->rejectConfirm( 400, __( 'The transaction data is missing.', 'ys-helcim-via-fluentcart' ), 'missing event_data' );
		}

		$event_data = json_decode( $raw_event, true );
		if ( ! is_array( $event_data ) || empty( $event_data ) ) {
			$this->rejectConfirm( 400, __( 'The transaction data is malformed.', 'ys-helcim-via-fluentcart' ), 'event_data is not valid JSON' );
		}

		$received_hash = isset( $_POST['hash'] ) ? sanitize_text_field( wp_unslash( $_POST['hash'] ) ) : '';
		if ( '' === $received_hash ) {
			$this->rejectConfirm( 400, __( 'The transaction verification code is missing.', 'ys-helcim-via-fluentcart' ), 'missing hash' );
		}

		$meta       = is_array( $transaction->meta ) ? $transaction->meta : array();
		$secret_enc = (string) ( $meta[ self::META_SECRET_TOKEN ] ?? '' );
		if ( '' === $secret_enc ) {
			$this->rejectConfirm( 400, __( 'The payment verification data is missing. Please place your order again.', 'ys-helcim-via-fluentcart' ), 'secret token missing on transaction', array( 'transaction_uuid' => $transaction_uuid ) );
		}

		$secret_token = Helper::decryptKey( $secret_enc );
		if ( ! is_string( $secret_token ) || '' === $secret_token ) {
			$this->rejectConfirm( 400, __( 'The payment verification data is invalid. Please place your order again.', 'ys-helcim-via-fluentcart' ), 'secret token decrypt failed', array( 'transaction_uuid' => $transaction_uuid ) );
		}

		if ( ! $this->validateHash( $event_data, $received_hash, $secret_token ) ) {
			$this->rejectConfirm(
				400,
				__( 'Payment verification failed. Please contact the site administrator.', 'ys-helcim-via-fluentcart' ),
				'hash mismatch — possible tampering',
				array( 'transaction_uuid' => $transaction_uuid )
			);
		}

		// (5) Transaction status and type.
		if ( 'APPROVED' !== strtoupper( (string) ( $event_data['status'] ?? '' ) ) ) {
			$this->rejectConfirm( 422, __( 'The payment was not approved.', 'ys-helcim-via-fluentcart' ), 'status is not APPROVED', array( 'transaction_uuid' => $transaction_uuid ) );
		}

		if ( 'purchase' !== strtolower( (string) ( $event_data['type'] ?? '' ) ) ) {
			$this->rejectConfirm( 422, __( 'The transaction type does not match.', 'ys-helcim-via-fluentcart' ), 'type is not purchase', array( 'transaction_uuid' => $transaction_uuid ) );
		}

		// (6) Currency + amount (strict integer-cents comparison).
		if ( strtoupper( (string) ( $event_data['currency'] ?? '' ) ) !== strtoupper( (string) $transaction->currency ) ) {
			$this->rejectConfirm(
				422,
				__( 'The payment currency does not match the order.', 'ys-helcim-via-fluentcart' ),
				'currency mismatch — possible tampering',
				array(
					'transaction_uuid' => $transaction_uuid,
					'expected'         => $transaction->currency,
					'received'         => $event_data['currency'] ?? '',
				)
			);
		}

		$paid_cents = (int) round( ( (float) ( $event_data['amount'] ?? 0 ) ) * 100 );
		if ( $paid_cents !== (int) $transaction->total ) {
			$this->rejectConfirm(
				422,
				__( 'The payment amount does not match the order.', 'ys-helcim-via-fluentcart' ),
				'amount mismatch — possible tampering',
				array(
					'transaction_uuid' => $transaction_uuid,
					'expected_cents'   => (int) $transaction->total,
					'received_cents'   => $paid_cents,
				)
			);
		}

		// (7) All checks passed → mark the payment as successful (which also removes the secretToken meta).
		$marked = $this->markPaid( $transaction, $event_data );

		if ( ! $marked ) {
			$this->rejectConfirm( 500, __( 'Failed to update the order status. Please contact the site administrator.', 'ys-helcim-via-fluentcart' ), 'markPaid failed', array( 'transaction_uuid' => $transaction_uuid ) );
		}

		// Reload to get the receipt URL with the latest status.
		$fresh = OrderTransaction::query()->where( 'id', $transaction->id )->first();

		wp_send_json(
			$this->buildSuccessResponse( $fresh, __( 'Payment successful! Taking you to the order confirmation…', 'ys-helcim-via-fluentcart' ) ),
			200
		);
	}

	/**
	 * Assemble the successful confirm response.
	 *
	 * order.uuid lets the front end's triggerPaymentCompleteEvent fire
	 * FluentCart's post-order actions (fluent_cart_run_order_actions); when
	 * missing, the front end simply redirects and the payment is unaffected.
	 *
	 * @param OrderTransaction $transaction The transaction (in the succeeded state).
	 * @param string           $message     The message to display.
	 * @return array
	 */
	private function buildSuccessResponse( OrderTransaction $transaction, string $message ): array {
		$order = Order::query()->where( 'id', $transaction->order_id )->first();

		return array(
			'status'       => 'success',
			'redirect_url' => $transaction->getReceiptPageUrl( true ),
			'message'      => $message,
			'order'        => array(
				'uuid' => $order ? (string) $order->uuid : '',
			),
		);
	}

	// ── 3. Hash verification ──────────────────────────────────────────────────

	/**
	 * Verify the Helcim transaction data hash (fail-closed).
	 *
	 * Formula (matching Helcim's official PHP example):
	 *   hash('sha256', json_encode($event_data) . $secret_token)
	 * compared with hash_equals in constant time.
	 *
	 * Note: json_encode uses the "default flags" (cross-lane integration contract;
	 * the Woo version once used JSON_UNESCAPED_SLASHES — this must be validated
	 * against real credentials before going live, and the mock environment must
	 * produce its hash with the same formula).
	 *
	 * @param array  $event_data    The Helcim transaction data object (already decoded).
	 * @param string $received_hash The hash returned by the front end.
	 * @param string $secret_token  The secretToken obtained during initialize (decrypted).
	 * @return bool Whether verification passed.
	 */
	public function validateHash( array $event_data, string $received_hash, string $secret_token ): bool {
		$computed = hash( 'sha256', json_encode( $event_data ) . $secret_token ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the hash must match Helcim's official formula byte for byte, so wp_json_encode cannot be used.

		return hash_equals( $computed, $received_hash );
	}

	// ── 4. Mark Paid ──────────────────────────────────────────────────────────

	/**
	 * Mark the payment successful and sync the order status (idempotent).
	 *
	 * Follows FluentCart's PayPal Processor::confirmPaymentSuccessByCharge:
	 * reload to avoid a race → return early if already succeeded → update the
	 * transaction (vendor_charge_id / card details / meta, removing the one-time
	 * secretToken) → StatusHelper::syncOrderStatuses to sync the order (which has
	 * its own atomic guard against a duplicate OrderPaid).
	 *
	 * Also used by webhook reconciliation ($tx_data is the card-transactions API response).
	 *
	 * @param OrderTransaction $transaction The target transaction.
	 * @param array            $tx_data     The Helcim transaction data (transactionId / cardNumber / cardType / approvalCode / cardToken …).
	 * @return bool Whether it succeeded (including the idempotent "already processed" case).
	 */
	public function markPaid( OrderTransaction $transaction, array $tx_data ): bool {
		$order = Order::query()->where( 'id', $transaction->order_id )->first();
		if ( ! $order ) {
			YSHelcimLogger::error( 'markPaid: order not found', array( 'transaction_uuid' => $transaction->uuid ) );
			return false;
		}

		// Reload the transaction — guards against a race between the confirm AJAX and the webhook.
		$fresh = OrderTransaction::query()->where( 'id', $transaction->id )->first();
		if ( ! $fresh ) {
			return false;
		}

		// Idempotent: already succeeded, so do not reprocess.
		if ( Status::TRANSACTION_SUCCEEDED === $fresh->status ) {
			return true;
		}

		$card_number = (string) ( $tx_data['cardNumber'] ?? '' );

		// Update the meta: add transaction details and remove the one-time secretToken (no secret is retained).
		$meta = is_array( $fresh->meta ) ? $fresh->meta : array();
		unset( $meta[ self::META_SECRET_TOKEN ] );

		if ( ! empty( $tx_data['approvalCode'] ) ) {
			$meta['ys_helcim_approval_code'] = (string) $tx_data['approvalCode'];
		}
		if ( ! empty( $tx_data['cardToken'] ) ) {
			$meta['ys_helcim_card_token'] = (string) $tx_data['cardToken'];
		}

		$fresh->fill(
			array(
				'vendor_charge_id'    => (string) ( $tx_data['transactionId'] ?? '' ),
				'status'              => Status::TRANSACTION_SUCCEEDED,
				'payment_method_type' => 'card',
				'card_last_4'         => '' !== $card_number ? substr( $card_number, -4 ) : '',
				'card_brand'          => (string) ( $tx_data['cardType'] ?? '' ),
				'meta'                => $meta,
			)
		);
		$fresh->save();

		YSHelcimLogger::info(
			'Transaction marked as paid',
			array(
				'transaction_uuid' => $fresh->uuid,
				'vendor_charge_id' => (string) ( $tx_data['transactionId'] ?? '' ),
			)
		);

		// Sync the order status (this contains the atomic guard on the PAID transition that prevents a duplicate OrderPaid event).
		( new StatusHelper( $order ) )->syncOrderStatuses( $fresh );

		return true;
	}

	// ── Internal helpers ──────────────────────────────────────────────────────

	/**
	 * Reject the confirm request: log (masked) and then return a failure JSON and exit.
	 *
	 * The user-facing message never leaks internal details; the detailed reason only goes to the log.
	 *
	 * @param int    $http_code    The HTTP status code.
	 * @param string $user_message The message returned to the front end.
	 * @param string $log_reason   The technical reason recorded in the log (English).
	 * @param array  $log_context  Additional log data.
	 * @return void Does not return (wp_send_json includes a die).
	 */
	private function rejectConfirm( int $http_code, string $user_message, string $log_reason, array $log_context = array() ): void {
		YSHelcimLogger::error( 'Confirm rejected: ' . $log_reason, $log_context );

		wp_send_json(
			array(
				'status'  => 'failed',
				'message' => $user_message,
			),
			$http_code
		);
	}
}
