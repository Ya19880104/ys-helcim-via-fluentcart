<?php
/**
 * Durable HelcimPay.js hosted checkout processor.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\HelcimPay;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Services\Payments\PaymentInstance;
use YangSheep\Helcim\FluentCart\HelcimJs\YSHelcimJsPurchaseRuntime;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationRepository;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationState;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimPurchaseOperation;
use YangSheep\Helcim\FluentCart\Support\YSHelcimApiClient;
use YangSheep\Helcim\FluentCart\Support\YSHelcimLogger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates a durable attempt before opening HelcimPay and confirms exact proof.
 */
class YSHelcimPayProcessor {

	public const NONCE_ACTION = 'ys_helcim_fct_confirm_pay';

	public const GATEWAY_SLUG = 'ys_helcim';

	private const META_CHECKOUT_TOKEN = 'ys_helcim_checkout_token';

	private const META_SECRET_TOKEN = 'ys_helcim_secret_token_enc';

	private const META_OPERATION_UUID = 'ys_helcim_operation_uuid';

	private const MAX_EVENT_DATA_BYTES = 131072;

	private const MAX_EVENT_JSON_DEPTH = 32;

	private const TERMINAL_META_KEYS = array(
		self::META_CHECKOUT_TOKEN,
		self::META_SECRET_TOKEN,
		'ys_helcim_card_token',
		self::META_OPERATION_UUID,
		'ys_helcim_initialized_at',
	);

	/** @var callable */
	private $api_request;

	/** @var callable */
	private $transaction_loader;

	private YSHelcimPayInitializationCoordinator $initialization;

	private YSHelcimPayConfirmationService $confirmation;

	private ?PaymentInstance $legacy_payment_instance = null;

	public function __construct(
		private YSHelcimPaySettings $settings,
		?YSHelcimOperationRepository $operations = null,
		?callable $api_request = null,
		?callable $transaction_loader = null,
		?callable $uuid_factory = null,
		?callable $confirm_token_factory = null,
		?callable $initialization_clock = null
	) {
		if ( null === $operations ) {
			global $wpdb;
			$operations = new YSHelcimOperationRepository( $wpdb );
		}

		$this->api_request = $api_request ?? static fn (
			string $endpoint,
			array $payload,
			string $api_token,
			?string $idempotency_key = null,
			string $method = 'POST'
		) => YSHelcimApiClient::request( $endpoint, $payload, $api_token, $idempotency_key, $method );
		$this->transaction_loader = $transaction_loader ?? static fn ( int $transaction_id ) => OrderTransaction::query()
			->where( 'id', $transaction_id )
			->first();

		$runtime = new YSHelcimJsPurchaseRuntime(
			settings: $settings,
			operations: $operations,
			api_request: $this->api_request,
			transaction_loader: $this->transaction_loader,
			method_slug: self::GATEWAY_SLUG,
			terminal_meta_keys: self::TERMINAL_META_KEYS
		);
		$this->initialization = new YSHelcimPayInitializationCoordinator(
			$operations,
			fn ( array $identity, string $correlation ) => $this->initializeProvider( $identity, $correlation ),
			$uuid_factory,
			$confirm_token_factory,
			$initialization_clock
		);
		$this->confirmation = new YSHelcimPayConfirmationService( $operations, $runtime );
		$this->operations   = $operations;
	}

	private YSHelcimOperationRepository $operations;

	/**
	 * Create and persist a provider checkout session for one exact FC transaction.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function initialize( PaymentInstance $payment_instance ) {
		$given = $payment_instance->transaction;
		if ( ! $given instanceof OrderTransaction || (int) ( $given->id ?? 0 ) <= 0 ) {
			return self::error( 'ys_helcim_init_failed', 'The transaction could not be found. Please refresh the page and try again.' );
		}

		$transaction = $this->loadExactTransaction( (int) $given->id );
		if ( is_wp_error( $transaction ) ) {
			return $transaction;
		}
		$identity = $this->transactionIdentity( $transaction );
		if ( is_wp_error( $identity ) ) {
			return $identity;
		}
		if (
			Status::TRANSACTION_PENDING !== (string) $transaction->status ||
			! self::isEmptyProviderId( $transaction->vendor_charge_id ?? null )
		) {
			return self::error( 'ys_helcim_init_state_unsafe', 'The transaction is not in a safe state for a new payment session.' );
		}

		$previous_payment_instance        = $this->legacy_payment_instance;
		$this->legacy_payment_instance = $payment_instance;
		try {
			$session = $this->initialization->begin( $identity );
		} finally {
			$this->legacy_payment_instance = $previous_payment_instance;
		}
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$stored = $this->persistBrowserSession( $transaction, $session );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		YSHelcimLogger::info(
			'HelcimPay checkout session initialized',
			array(
				'transaction_uuid' => (string) $transaction->uuid,
				'operation_uuid'   => (string) $session['operation_uuid'],
			)
		);

		return array(
			'status'       => 'success',
			'nextAction'   => self::GATEWAY_SLUG,
			'actionName'   => 'custom',
			'message'      => __( 'Your order has been created. Please complete the credit card payment in the secure payment window.', 'ys-helcim-via-fluentcart' ),
			'payment_data' => array(
				'checkout_token'   => (string) $session['checkout_token'],
				'transaction_uuid' => (string) $transaction->uuid,
				'operation_uuid'   => (string) $session['operation_uuid'],
				'confirm_token'    => (string) $session['confirm_token'],
				'confirm_nonce'    => wp_create_nonce( self::NONCE_ACTION ),
				'mode'             => (string) $identity['payment_mode'],
			),
		);
	}

	/**
	 * Hard-gated public confirmation endpoint.
	 */
	public function handleConfirmAjax(): void {
		$nonce = self::postedText( 'nonce' );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			$this->rejectConfirm( 403, 'ys_helcim_confirm_nonce_invalid', 'The payment confirmation session is invalid or expired.' );
		}

		$transaction_uuid = self::postedText( 'transaction_uuid' );
		$operation_uuid   = self::postedText( 'operation_uuid' );
		$confirm_token    = self::postedText( 'confirm_token' );
		$received_hash    = self::postedText( 'hash' );
		$raw_event        = self::postedRawString( 'event_data' );
		$event_data       = null;
		if ( '' !== $raw_event && strlen( $raw_event ) <= self::MAX_EVENT_DATA_BYTES ) {
			try {
				$event_data = json_decode( $raw_event, true, self::MAX_EVENT_JSON_DEPTH, JSON_THROW_ON_ERROR );
			} catch ( \JsonException $exception ) {
				unset( $exception );
				$event_data = null;
			}
		}
		if ( ! is_array( $event_data ) ) {
			$this->rejectConfirm( 400, 'ys_helcim_confirm_event_invalid', 'The provider payment result is missing or malformed.' );
		}

		$result = $this->confirmation->confirm(
			$transaction_uuid,
			$operation_uuid,
			$confirm_token,
			$event_data,
			$received_hash
		);
		if ( is_wp_error( $result ) ) {
			$this->rejectConfirm( self::confirmationHttpStatus( $result ), $result->get_error_code(), $result->get_error_message() );
		}

		if ( 'succeeded' === ( $result['status'] ?? null ) ) {
			$transaction = OrderTransaction::query()
				->where( 'uuid', $transaction_uuid )
				->where( 'payment_method', self::GATEWAY_SLUG )
				->where( 'transaction_type', Status::TRANSACTION_TYPE_CHARGE )
				->first();
			if ( ! $transaction instanceof OrderTransaction ) {
				$this->rejectConfirm( 500, 'ys_helcim_confirm_receipt_missing', 'The paid transaction receipt could not be loaded.' );
			}

			$success_response = $this->buildSuccessResponse( $transaction );
			if ( is_wp_error( $success_response ) ) {
				$this->rejectConfirm( 500, $success_response->get_error_code(), $success_response->get_error_message() );
			}

			wp_send_json( $success_response, 200 );
		}

		if ( 'declined' === ( $result['status'] ?? null ) ) {
			wp_send_json(
				array(
					'status'        => 'failed',
					'error_code'    => 'provider_declined',
					'message'       => __( 'The card was declined. No payment was recorded; you may check the details and try again.', 'ys-helcim-via-fluentcart' ),
					'retry_allowed' => true,
				),
				422
			);
		}

		$this->rejectConfirm(
			409,
			'ys_helcim_confirm_attention_required',
			'The payment result needs reconciliation. Do not submit another payment.'
		);
	}

	/**
	 * @param array<string, int|string> $identity
	 * @return array{checkoutToken:string,secretToken:string}|\WP_Error
	 */
	private function initializeProvider( array $identity, string $correlation ) {
		$api_token = $this->settings->getApiTokenForMode( (string) ( $identity['payment_mode'] ?? '' ) );
		if ( '' === trim( $api_token ) ) {
			return self::neverSentError( 'ys_helcim_initialize_credential_missing', 'The Helcim API credential for this transaction mode is unavailable.' );
		}

		$amount  = (float) number_format( (int) $identity['amount'] / 100, 2, '.', '' );
		$invoice = array(
			'invoiceNumber' => $correlation,
			'lineItems'     => array(
				array(
					'sku'         => 'YSFC-' . (int) $identity['transaction_id'],
					'description' => 'FluentCart order ' . (int) $identity['order_id'],
					'quantity'    => 1,
					'price'       => $amount,
					'total'       => $amount,
				),
			),
		);
		$payload = array(
			'paymentType'   => 'purchase',
			'amount'        => $amount,
			'currency'      => (string) $identity['currency'],
			'paymentMethod' => 'cc',
			'invoiceRequest' => $invoice,
		);

		try {
			$filtered = apply_filters( 'ys_helcim_fct_initialize_args', $payload, $this->legacy_payment_instance );
			$filtered = apply_filters( 'ys_helcim_fct_initialize_args_v2', $filtered, $identity, $correlation );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$api_token = '';
			return self::neverSentError( 'ys_helcim_initialize_payload_invalid', 'The hosted checkout request could not be prepared.' );
		}
		if ( ! is_array( $filtered ) ) {
			$api_token = '';
			return self::neverSentError( 'ys_helcim_initialize_payload_invalid', 'The hosted checkout request is invalid.' );
		}

		unset( $filtered['invoiceNumber'] );
		$filtered['paymentType']    = 'purchase';
		$filtered['amount']         = $amount;
		$filtered['currency']       = (string) $identity['currency'];
		$filtered['paymentMethod']  = 'cc';
		$filtered['invoiceRequest'] = $invoice;

		try {
			$response = ( $this->api_request )(
				'helcim-pay/initialize',
				$filtered,
				$api_token,
				null,
				'POST'
			);
		} finally {
			$api_token = '';
		}
		if ( is_wp_error( $response ) || ! is_array( $response ) ) {
			return is_wp_error( $response )
				? $response
				: self::error( 'ys_helcim_initialize_response_invalid', 'Helcim returned an invalid hosted checkout response.' );
		}

		return $response;
	}

	/**
	 * Persist encrypted confirmation material before any token reaches the browser.
	 *
	 * @param array<string, mixed> $session
	 * @return true|\WP_Error
	 */
	private function persistBrowserSession( OrderTransaction $transaction, array $session ) {
		try {
			$secret_ciphertext = Helper::encryptKey( (string) $session['secret_token'] );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$secret_ciphertext = false;
		}
		if (
			! is_string( $secret_ciphertext ) ||
			'' === $secret_ciphertext ||
			! is_callable( array( Helper::class, 'isValueEncrypted' ) ) ||
			! Helper::isValueEncrypted( $secret_ciphertext )
		) {
			return $this->failUnexposedSession( (string) $session['operation_uuid'], (int) $transaction->id );
		}

		$fresh = $this->loadExactTransaction( (int) $transaction->id );
		if ( is_wp_error( $fresh ) || (string) $fresh->uuid !== (string) $transaction->uuid ) {
			return $this->failUnexposedSession( (string) $session['operation_uuid'], (int) $transaction->id );
		}
		$meta = is_array( $fresh->meta ?? null ) ? $fresh->meta : array();
		unset( $meta['ys_helcim_card_token'] );
		$meta[ self::META_CHECKOUT_TOKEN ] = (string) $session['checkout_token'];
		$meta[ self::META_SECRET_TOKEN ]   = $secret_ciphertext;
		$meta[ self::META_OPERATION_UUID ] = (string) $session['operation_uuid'];
		$meta['ys_helcim_initialized_at']  = gmdate( 'Y-m-d H:i:s' );

		try {
			$fresh->fill( array( 'meta' => $meta ) );
			$saved = $fresh->save();
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$saved = false;
		}
		$verified = true === $saved ? $this->loadExactTransaction( (int) $transaction->id ) : null;
		$verified_meta = $verified instanceof OrderTransaction && is_array( $verified->meta ?? null )
			? $verified->meta
			: array();
		if (
			true !== $saved ||
			! $verified instanceof OrderTransaction ||
			! hash_equals( (string) $session['checkout_token'], (string) ( $verified_meta[ self::META_CHECKOUT_TOKEN ] ?? '' ) ) ||
			! hash_equals( $secret_ciphertext, (string) ( $verified_meta[ self::META_SECRET_TOKEN ] ?? '' ) ) ||
			! hash_equals( (string) $session['operation_uuid'], (string) ( $verified_meta[ self::META_OPERATION_UUID ] ?? '' ) )
		) {
			return $this->failUnexposedSession( (string) $session['operation_uuid'], (int) $transaction->id );
		}

		return true;
	}

	/** @return \WP_Error */
	private function failUnexposedSession( string $operation_uuid, int $transaction_id ): \WP_Error {
		if ( ! $this->purgeUnexposedSessionMeta( $transaction_id ) ) {
			return self::error( 'ys_helcim_journal_outcome_unpersisted', 'The checkout session was withheld, but its safe terminal state could not be recorded.' );
		}

		try {
			$transitioned = $this->operations->transitionRemote(
				$operation_uuid,
				YSHelcimOperationState::REMOTE_PROCESSING,
				YSHelcimOperationState::REMOTE_FAILED,
				array(
					'error_code'    => 'hosted_session_not_persisted',
					'error_message' => 'The checkout tokens were not exposed because their local readback failed.',
				)
			);
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$transitioned = false;
		}
		if ( true !== $transitioned ) {
			return self::error( 'ys_helcim_journal_outcome_unpersisted', 'The checkout session was withheld, but its safe terminal state could not be recorded.' );
		}

		try {
			$verified = $this->operations->findByUuidStrict( $operation_uuid );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$verified = null;
		}
		if (
			! is_array( $verified ) ||
			YSHelcimOperationState::REMOTE_FAILED !== (string) ( $verified['remote_status'] ?? '' ) ||
			null !== ( $verified['active_scope_key'] ?? null ) ||
			null !== ( $verified['vendor_transaction_id'] ?? null )
		) {
			return self::error( 'ys_helcim_journal_outcome_unpersisted', 'The checkout session was withheld, but its safe terminal state could not be recorded.' );
		}

		return self::error( 'ys_helcim_initialize_persistence_failed', 'The secure checkout session could not be saved. No payment window was opened.' );
	}

	private function purgeUnexposedSessionMeta( int $transaction_id ): bool {
		$fresh = $this->loadExactTransaction( $transaction_id );
		if ( is_wp_error( $fresh ) ) {
			return false;
		}

		$meta    = is_array( $fresh->meta ?? null ) ? $fresh->meta : array();
		$changed = false;
		foreach ( self::TERMINAL_META_KEYS as $key ) {
			if ( array_key_exists( $key, $meta ) ) {
				unset( $meta[ $key ] );
				$changed = true;
			}
		}
		if ( ! $changed ) {
			return true;
		}

		try {
			$fresh->fill( array( 'meta' => $meta ) );
			$saved = $fresh->save();
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$saved = false;
		}
		$verified      = true === $saved ? $this->loadExactTransaction( $transaction_id ) : null;
		$verified_meta = $verified instanceof OrderTransaction && is_array( $verified->meta ?? null )
			? $verified->meta
			: array();
		if ( true !== $saved || ! $verified instanceof OrderTransaction ) {
			return false;
		}
		foreach ( self::TERMINAL_META_KEYS as $key ) {
			if ( array_key_exists( $key, $verified_meta ) ) {
				return false;
			}
		}

		return true;
	}

	/** @return OrderTransaction|\WP_Error */
	private function loadExactTransaction( int $transaction_id ) {
		$loaded = ( $this->transaction_loader )( $transaction_id );
		return $loaded instanceof OrderTransaction && (int) $loaded->id === $transaction_id
			? $loaded
			: self::error( 'ys_helcim_init_failed', 'The exact FluentCart transaction could not be loaded.' );
	}

	/** @return array<string, int|string>|\WP_Error */
	private function transactionIdentity( OrderTransaction $transaction ) {
		$operation = YSHelcimPurchaseOperation::fromTransaction(
			array(
				'gateway'          => (string) ( $transaction->payment_method ?? '' ),
				'order_id'         => (int) ( $transaction->order_id ?? 0 ),
				'transaction_id'   => (int) ( $transaction->id ?? 0 ),
				'transaction_uuid' => (string) ( $transaction->uuid ?? '' ),
				'amount'           => (int) ( $transaction->total ?? 0 ),
				'currency'         => (string) ( $transaction->currency ?? '' ),
				'payment_mode'     => (string) ( $transaction->payment_mode ?? '' ),
			)
		);

		return is_wp_error( $operation ) ? $operation : $operation->identity();
	}

	/** @return array<string, mixed>|\WP_Error */
	private function buildSuccessResponse( OrderTransaction $transaction ) {
		$order = Order::query()->where( 'id', (int) $transaction->order_id )->first();
		if (
			! $order instanceof Order ||
			(int) ( $order->id ?? 0 ) !== (int) $transaction->order_id ||
			'' === trim( (string) ( $order->uuid ?? '' ) )
		) {
			return self::error( 'ys_helcim_confirm_receipt_missing', 'The paid transaction receipt could not be loaded.' );
		}

		return array(
			'status'       => 'success',
			'redirect_url' => $transaction->getReceiptPageUrl( true ),
			'message'      => __( 'Payment successful! Taking you to the order confirmation.', 'ys-helcim-via-fluentcart' ),
			'order'        => array(
				'uuid' => (string) $order->uuid,
			),
		);
	}

	private function rejectConfirm( int $http_code, string $error_code, string $message ): void {
		wp_send_json(
			array(
				'status'        => 'failed',
				'error_code'    => $error_code,
				'message'       => __( $message, 'ys-helcim-via-fluentcart' ),
				'retry_allowed' => false,
			),
			$http_code
		);
	}

	private static function confirmationHttpStatus( \WP_Error $error ): int {
		$code = $error->get_error_code();
		$map  = array(
			400 => array(
				'ys_helcim_confirm_event_invalid',
				'ys_helcim_confirm_transaction_invalid',
				'ys_helcim_invalid_purchase',
			),
			404 => array(
				'ys_helcim_confirm_transaction_missing',
				'ys_helcim_purchase_transaction_missing',
				'ys_helcim_purchase_order_missing',
			),
			409 => array(
				'ys_helcim_operation_conflict',
				'ys_helcim_scope_busy',
				'ys_helcim_local_purchase_already_succeeded',
				'ys_helcim_purchase_order_state_unsafe',
				'ys_helcim_purchase_provider_id_mismatch',
				'ys_helcim_purchase_identity_changed',
				'ys_helcim_purchase_transaction_mismatch',
				'ys_helcim_purchase_local_binding_unknown',
			),
			422 => array(
				'ys_helcim_confirm_operation_invalid',
				'ys_helcim_confirm_correlation_invalid',
				'ys_helcim_confirm_hash_invalid',
				'ys_helcim_confirm_proof_invalid',
				'ys_helcim_confirm_token_invalid',
				'ys_helcim_confirm_secret_missing',
				'ys_helcim_confirm_secret_invalid',
				'ys_helcim_purchase_provider_id_invalid',
			),
		);

		foreach ( $map as $status => $codes ) {
			if ( in_array( $code, $codes, true ) ) {
				return $status;
			}
		}

		return 503;
	}

	private static function postedText( string $key ): string {
		$value = self::postedRawString( $key );
		return '' === $value ? '' : sanitize_text_field( $value );
	}

	private static function postedRawString( string $key ): string {
		if ( ! isset( $_POST[ $key ] ) || ! is_string( $_POST[ $key ] ) ) {
			return '';
		}

		$value = wp_unslash( $_POST[ $key ] );
		return is_string( $value ) ? $value : '';
	}

	private static function isEmptyProviderId( mixed $provider_id ): bool {
		return null === $provider_id || '' === trim( (string) $provider_id );
	}

	private static function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, __( $message, 'ys-helcim-via-fluentcart' ) );
	}

	private static function neverSentError( string $code, string $message ): \WP_Error {
		return new \WP_Error(
			$code,
			__( $message, 'ys-helcim-via-fluentcart' ),
			array(
				'kind'                 => 'local',
				'indeterminate'        => false,
				'mutation_disposition' => YSHelcimApiClient::MUTATION_NEVER_SENT,
			)
		);
	}
}
