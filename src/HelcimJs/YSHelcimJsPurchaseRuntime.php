<?php
/**
 * Reusable production runtime for Helcim.js purchase execution and reconciliation.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\HelcimJs;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationRepository;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimPurchaseCoordinator;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimPurchaseOperation;
use YangSheep\Helcim\FluentCart\Settings\YSHelcimModeApiSettings;
use YangSheep\Helcim\FluentCart\Support\YSHelcimApiClient;
use YangSheep\Helcim\FluentCart\Support\YSHelcimTransactionId;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the one coordinator plus its exact FluentCart and Helcim adapters.
 *
 * Card tokens remain call-local. API credentials are selected from the stored
 * transaction mode and are never copied into the journal, FluentCart meta, or
 * runtime properties.
 */
final class YSHelcimJsPurchaseRuntime {
	private const DEFAULT_METHOD_SLUG = 'ys_helcim_js';

	/** @var callable */
	private $api_request;

	/** @var callable */
	private $transaction_loader;

	/** @var callable */
	private $order_loader;

	/** @var callable */
	private $status_sync;

	/** @var callable */
	private $payload_filter;

	/** @var callable */
	private $client_ip;

	private YSHelcimPurchaseCoordinator $coordinator;

	private YSHelcimOperationRepository $operations;

	private string $method_slug;

	/** @var string[] */
	private array $terminal_meta_keys;

	/**
	 * @param callable|null $api_request        API client signature matches YSHelcimApiClient::request.
	 * @param callable|null $transaction_loader `(int transaction_id) -> OrderTransaction|null`.
	 * @param callable|null $order_loader       `(int order_id) -> Order|null`.
	 * @param callable|null $status_sync        `(Order, OrderTransaction) -> bool`.
	 * @param callable|null $uuid_factory       Durable operation UUID factory.
	 * @param callable|null $clock              UTC SQL timestamp provider.
	 * @param callable|null $payload_filter     `(payload, transaction, operation_uuid) -> array`.
	 * @param callable|null $client_ip          Trusted client IP provider.
	 */
	public function __construct(
		private YSHelcimModeApiSettings $settings,
		YSHelcimOperationRepository $operations,
		?callable $api_request = null,
		?callable $transaction_loader = null,
		?callable $order_loader = null,
		?callable $status_sync = null,
		?callable $uuid_factory = null,
		?callable $clock = null,
		?callable $payload_filter = null,
		?callable $client_ip = null,
		string $method_slug = self::DEFAULT_METHOD_SLUG,
		array $terminal_meta_keys = array()
	) {
		$method_slug = strtolower( trim( $method_slug ) );
		if ( ! in_array( $method_slug, array( 'ys_helcim', 'ys_helcim_js' ), true ) ) {
			throw new \InvalidArgumentException( 'Unsupported Helcim payment method.' );
		}
		$this->method_slug = $method_slug;
		$this->terminal_meta_keys = array_values(
			array_unique(
				array_filter(
					array_map( 'strval', $terminal_meta_keys ),
					static fn ( string $key ): bool => 1 === preg_match( '/\A[a-z0-9_]{1,191}\z/', $key )
				)
			)
		);
		$this->operations = $operations;
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
		$this->order_loader = $order_loader ?? static fn ( int $order_id ) => Order::query()
			->where( 'id', $order_id )
			->first();
		$this->status_sync = $status_sync ?? static function ( Order $order, OrderTransaction $transaction ): bool {
			$result = ( new StatusHelper( $order ) )->syncOrderStatuses( $transaction );
			return false !== $result;
		};
		$this->payload_filter = $payload_filter ?? static fn ( array $payload, OrderTransaction $transaction, string $operation_uuid ) => apply_filters(
			'ys_helcim_fct_purchase_args',
			$payload,
			$transaction,
			$operation_uuid
		);
		$this->client_ip = $client_ip ?? static fn (): string => self::clientIp();

		$this->coordinator = new YSHelcimPurchaseCoordinator(
			$operations,
			fn ( array $identity, string $card_token, string $idempotency_key, string $operation_uuid ) => $this->purchase(
				$identity,
				$card_token,
				$idempotency_key,
				$operation_uuid
			),
			fn ( array $identity, string $provider_id, string $operation_uuid ) => $this->bindLocal(
				$identity,
				$provider_id,
				$operation_uuid
			),
			fn ( array $identity, string $provider_id, string $operation_uuid ) => $this->inspectLocal(
				$identity,
				$provider_id,
				$operation_uuid
			),
			$uuid_factory ?? static fn (): string => wp_generate_uuid4(),
			$clock
		);
	}

	/** Build the production runtime without resolving or retaining credentials. */
	public static function forSettings( YSHelcimJsSettings $settings ): self {
		return new self( $settings, new YSHelcimOperationRepository() );
	}

	/**
	 * Execute or safely replay an inline purchase.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function executeInline( OrderTransaction $transaction, string $card_token ) {
		$loaded = $this->loadExactTransaction( $transaction );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}

		$identity = $this->identity( $loaded );
		if ( is_wp_error( $identity ) ) {
			return $identity;
		}

		if ( Status::TRANSACTION_SUCCEEDED === (string) $loaded->status ) {
			$provider_id = YSHelcimTransactionId::normalize( $loaded->vendor_charge_id ?? null );
			if ( null === $provider_id ) {
				return self::attentionResult( 'local_success_provider_id_invalid' );
			}

			$attempts = $this->operations->findPurchasesByIdentity( (int) $identity['transaction_id'] );
			if ( is_wp_error( $attempts ) ) {
				return $attempts;
			}
			if ( array() !== $attempts ) {
				return $this->coordinator->execute( $identity, $card_token, $provider_id );
			}

			$order_state = $this->orderState( $identity );
			return is_array( $order_state ) && 'paid' === $order_state['status']
				? $this->existingSuccess( $provider_id )
				: self::attentionResult( 'local_success_order_unproven' );
		}

		if ( ! self::isEmptyProviderId( $loaded->vendor_charge_id ?? null ) ) {
			$provider_id = YSHelcimTransactionId::normalize( $loaded->vendor_charge_id ?? null );
			if ( null === $provider_id ) {
				return self::attentionResult( 'unexpected_local_provider_binding' );
			}

			// FluentCart may reset a partially synchronized transaction to pending
			// while retaining its exact vendor ID. Resume only through the durable
			// succeeded journal proof, with an empty card token so this branch can
			// never create or invoke a new provider purchase.
			return $this->coordinator->execute( $identity, '', $provider_id );
		}

		$preflight = $this->preflight( $identity );
		if ( is_wp_error( $preflight ) ) {
			return $preflight;
		}

		return $this->coordinator->execute( $identity, $card_token );
	}

	/**
	 * Reconcile strict correlated proof without invoking payment/purchase.
	 *
	 * @param array<string, mixed> $proof Exact coordinator proof including operation_correlation.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function reconcileProviderProof(
		OrderTransaction $transaction,
		string $operation_uuid,
		array $proof
	) {
		$loaded = $this->loadExactTransaction( $transaction );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}

		$identity = $this->identity( $loaded );
		if ( is_wp_error( $identity ) ) {
			return $identity;
		}

		$result = $this->coordinator->reconcileProviderProof( $identity, $operation_uuid, $proof );
		if (
			is_wp_error( $result ) ||
			! in_array( $result['status'] ?? null, array( YSHelcimPurchaseCoordinator::SUCCEEDED, YSHelcimPurchaseCoordinator::DECLINED ), true )
		) {
			return $result;
		}

		$purged = $this->purgeTerminalMeta( $identity );
		return is_wp_error( $purged ) ? $purged : $result;
	}

	/**
	 * Provider callback. Its four arguments are deliberately identical to the
	 * coordinator contract; the operation UUID is the request correlation.
	 *
	 * @param array<string, int|string> $identity
	 * @return array<string, mixed>|\WP_Error
	 */
	private function purchase(
		array $identity,
		string $card_token,
		string $idempotency_key,
		string $operation_uuid
	) {
		$transaction = $this->loadForIdentity( $identity );
		if ( is_wp_error( $transaction ) ) {
			return $transaction;
		}

		if ( Status::TRANSACTION_SUCCEEDED === (string) $transaction->status ) {
			return new \WP_Error(
				'ys_helcim_local_purchase_already_succeeded',
				__( 'The local payment already has a result and must not be charged again.', 'ys-helcim-via-fluentcart' ),
				array( 'indeterminate' => true )
			);
		}

		$order_state = $this->orderState( $identity );
		if ( is_wp_error( $order_state ) || 'unpaid' !== $order_state['status'] ) {
			return is_wp_error( $order_state )
				? $order_state
				: self::runtimeError( 'ys_helcim_purchase_order_state_unsafe', 'The order is not in a safe unpaid state.' );
		}

		$api_token = $this->credentialForIdentity( $identity );
		if ( is_wp_error( $api_token ) ) {
			return $api_token;
		}

		$order = $order_state['order'];

		$amount = self::decimalAmount( (int) $identity['amount'] );
		$payload = array(
			'ipAddress' => (string) ( $this->client_ip )(),
			'currency'  => (string) $identity['currency'],
			'amount'    => $amount,
			'cardData'  => array( 'cardToken' => $card_token ),
			'invoice'   => self::invoice( $identity, $operation_uuid, $amount ),
			'ecommerce' => true,
		);
		$billing_address = self::billingAddress( $order );
		if ( array() !== $billing_address ) {
			$payload['billingAddress'] = $billing_address;
		}

		try {
			$filtered = ( $this->payload_filter )( $payload, $transaction, $operation_uuid );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::runtimeError( 'ys_helcim_purchase_payload_filter_failed', 'The payment payload could not be prepared.' );
		}
		if ( ! is_array( $filtered ) ) {
			return self::runtimeError( 'ys_helcim_purchase_payload_invalid', 'The payment payload is invalid.' );
		}

		// Re-assert every immutable/correlation field after extension filters.
		unset( $filtered['invoiceNumber'] );
		$filtered['ipAddress'] = $payload['ipAddress'];
		$filtered['currency']  = $identity['currency'];
		$filtered['amount']    = $amount;
		$filtered['cardData']  = array( 'cardToken' => $card_token );
		$filtered['invoice']   = self::invoice( $identity, $operation_uuid, $amount );
		$filtered['ecommerce'] = true;

		try {
			$provider_result = ( $this->api_request )(
				'payment/purchase',
				$filtered,
				$api_token,
				$idempotency_key,
				'POST'
			);
		} finally {
			// Drop the only explicit runtime-local credential reference immediately.
			$api_token = '';
		}

		return YSHelcimJsPurchaseResponseAdapter::toCoordinatorOutcome( $provider_result, $identity );
	}

	/**
	 * @param array<string, int|string> $identity
	 * @return array{bound:bool,provider_transaction_id:string}|\WP_Error
	 */
	private function bindLocal( array $identity, string $provider_id, string $operation_uuid ) {
		unset( $operation_uuid );
		$provider_id = YSHelcimTransactionId::normalize( $provider_id );
		if ( null === $provider_id ) {
			return self::runtimeError( 'ys_helcim_purchase_provider_id_invalid', 'The provider transaction ID is invalid.' );
		}

		$transaction = $this->loadForIdentity( $identity );
		if ( is_wp_error( $transaction ) ) {
			return $transaction;
		}

		$observed_id = YSHelcimTransactionId::normalize( $transaction->vendor_charge_id ?? null );
		$order_state = $this->orderState( $identity );
		if ( is_wp_error( $order_state ) ) {
			return $order_state;
		}
		$meta       = is_array( $transaction->meta ?? null ) ? $transaction->meta : array();
		$meta_dirty = false;
		foreach ( $this->terminal_meta_keys as $meta_key ) {
			if ( array_key_exists( $meta_key, $meta ) ) {
				unset( $meta[ $meta_key ] );
				$meta_dirty = true;
			}
		}
		if ( Status::TRANSACTION_SUCCEEDED === (string) $transaction->status ) {
			if ( $provider_id !== $observed_id ) {
				return self::runtimeError( 'ys_helcim_purchase_provider_id_mismatch', 'A different provider transaction is already bound.' );
			}
			if ( $meta_dirty ) {
				try {
					$transaction->fill( array( 'meta' => $meta ) );
					$meta_saved = $transaction->save();
				} catch ( \Throwable $exception ) {
					unset( $exception );
					$meta_saved = false;
				}
				if ( true !== $meta_saved ) {
					return self::runtimeError( 'ys_helcim_purchase_meta_purge_failed', 'Sensitive payment reconciliation data could not be removed.' );
				}
				$verified_meta = $this->loadForIdentity( $identity );
				if (
					is_wp_error( $verified_meta ) ||
					$this->hasTerminalMeta( is_array( $verified_meta->meta ?? null ) ? $verified_meta->meta : array() )
				) {
					return self::runtimeError( 'ys_helcim_purchase_meta_purge_unverified', 'Sensitive payment reconciliation data could not be proven removed.' );
				}
			}
			if ( 'paid' === $order_state['status'] ) {
				return array( 'bound' => true, 'provider_transaction_id' => $provider_id );
			}
			if ( 'unpaid' !== $order_state['status'] ) {
				return self::runtimeError( 'ys_helcim_purchase_order_state_unsafe', 'The order payment state is not safe to resume.' );
			}
		}
		if (
			Status::TRANSACTION_SUCCEEDED !== (string) $transaction->status &&
			! self::isEmptyProviderId( $transaction->vendor_charge_id ?? null )
		) {
			if ( $provider_id !== $observed_id ) {
				return self::runtimeError( 'ys_helcim_purchase_provider_id_mismatch', 'A different provider transaction is already present locally.' );
			}
		}

		if ( Status::TRANSACTION_SUCCEEDED !== (string) $transaction->status ) {
			if ( 'unpaid' !== $order_state['status'] ) {
				return self::runtimeError( 'ys_helcim_purchase_order_state_unsafe', 'The order payment state is not safe to bind.' );
			}
			try {
				$transaction->fill(
					array(
						'status'              => Status::TRANSACTION_SUCCEEDED,
						'vendor_charge_id'    => $provider_id,
						'payment_method_type' => 'card',
						'meta'                => $meta,
					)
				);
				$saved = $transaction->save();
			} catch ( \Throwable $exception ) {
				unset( $exception );
				$saved = false;
			}
			if ( true !== $saved ) {
				return self::runtimeError( 'ys_helcim_purchase_save_failed', 'The FluentCart transaction could not be saved.' );
			}
		}

		$verified = $this->loadForIdentity( $identity );
		if (
			is_wp_error( $verified ) ||
			Status::TRANSACTION_SUCCEEDED !== (string) $verified->status ||
			$provider_id !== YSHelcimTransactionId::normalize( $verified->vendor_charge_id ?? null ) ||
			$this->hasTerminalMeta( is_array( $verified->meta ?? null ) ? $verified->meta : array() )
		) {
			return self::runtimeError( 'ys_helcim_purchase_save_unverified', 'The saved FluentCart transaction could not be verified.' );
		}

		$order_state = $this->orderState( $identity );
		if ( is_wp_error( $order_state ) ) {
			return $order_state;
		}
		if ( 'paid' === $order_state['status'] ) {
			return array( 'bound' => true, 'provider_transaction_id' => $provider_id );
		}
		if ( 'unpaid' !== $order_state['status'] ) {
			return self::runtimeError( 'ys_helcim_purchase_order_state_unsafe', 'The order payment state is not safe to synchronize.' );
		}
		$order = $order_state['order'];
		try {
			( $this->status_sync )( $order, $verified );
		} catch ( \Throwable $exception ) {
			unset( $exception );
		}

		$order_state = $this->orderState( $identity );
		if ( is_wp_error( $order_state ) || 'paid' !== $order_state['status'] ) {
			return self::runtimeError( 'ys_helcim_purchase_order_sync_failed', 'The FluentCart paid order state could not be proven.' );
		}

		return array( 'bound' => true, 'provider_transaction_id' => $provider_id );
	}

	/** @param array<string,mixed> $meta */
	private function hasTerminalMeta( array $meta ): bool {
		foreach ( $this->terminal_meta_keys as $meta_key ) {
			if ( array_key_exists( $meta_key, $meta ) ) {
				return true;
			}
		}

		return false;
	}

	/** @param array<string, int|string> $identity @return true|\WP_Error */
	private function purgeTerminalMeta( array $identity ) {
		if ( array() === $this->terminal_meta_keys ) {
			return true;
		}

		$transaction = $this->loadForIdentity( $identity );
		if ( is_wp_error( $transaction ) ) {
			return $transaction;
		}

		$meta = is_array( $transaction->meta ?? null ) ? $transaction->meta : array();
		if ( ! $this->hasTerminalMeta( $meta ) ) {
			return true;
		}
		foreach ( $this->terminal_meta_keys as $meta_key ) {
			unset( $meta[ $meta_key ] );
		}

		try {
			$transaction->fill( array( 'meta' => $meta ) );
			$saved = $transaction->save();
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$saved = false;
		}
		if ( true !== $saved ) {
			return self::runtimeError( 'ys_helcim_purchase_meta_purge_failed', 'Sensitive payment reconciliation data could not be removed.' );
		}

		$verified = $this->loadForIdentity( $identity );
		if (
			is_wp_error( $verified ) ||
			$this->hasTerminalMeta( is_array( $verified->meta ?? null ) ? $verified->meta : array() )
		) {
			return self::runtimeError( 'ys_helcim_purchase_meta_purge_unverified', 'Sensitive payment reconciliation data could not be proven removed.' );
		}

		return true;
	}

	/**
	 * @param array<string, int|string> $identity
	 * @return array{status:string,provider_transaction_id:?string}|\WP_Error
	 */
	private function inspectLocal( array $identity, string $provider_id, string $operation_uuid ) {
		unset( $operation_uuid );
		$provider_id = YSHelcimTransactionId::normalize( $provider_id );
		if ( null === $provider_id ) {
			return self::runtimeError( 'ys_helcim_purchase_provider_id_invalid', 'The provider transaction ID is invalid.' );
		}

		$transaction = $this->loadForIdentity( $identity );
		if ( is_wp_error( $transaction ) ) {
			return $transaction;
		}
		$raw_id      = $transaction->vendor_charge_id ?? null;
		$observed_id = YSHelcimTransactionId::normalize( $raw_id );
		$order_state = $this->orderState( $identity );
		if ( is_wp_error( $order_state ) ) {
			return $order_state;
		}

		if ( Status::TRANSACTION_SUCCEEDED === (string) $transaction->status ) {
			if ( $provider_id === $observed_id ) {
				if ( 'paid' === $order_state['status'] ) {
					return array( 'status' => 'bound', 'provider_transaction_id' => $provider_id );
				}
				if ( 'unpaid' === $order_state['status'] ) {
					return array( 'status' => 'partial', 'provider_transaction_id' => $provider_id );
				}
				return self::runtimeError( 'ys_helcim_purchase_order_state_unknown', 'The paid order state could not be proven.' );
			}
			if ( null !== $observed_id ) {
				return array( 'status' => 'mismatch', 'provider_transaction_id' => $observed_id );
			}
			return self::runtimeError( 'ys_helcim_purchase_local_binding_unknown', 'The successful local transaction has no exact provider ID.' );
		}

		if ( self::isEmptyProviderId( $raw_id ) && 'unpaid' === $order_state['status'] ) {
			return array( 'status' => 'unbound', 'provider_transaction_id' => null );
		}
		if ( $provider_id === $observed_id && 'unpaid' === $order_state['status'] ) {
			return array( 'status' => 'partial', 'provider_transaction_id' => $provider_id );
		}
		if ( null !== $observed_id && $provider_id !== $observed_id ) {
			return array( 'status' => 'mismatch', 'provider_transaction_id' => $observed_id );
		}

		return self::runtimeError( 'ys_helcim_purchase_local_binding_unknown', 'The local provider binding is incomplete.' );
	}

	/** @return OrderTransaction|\WP_Error */
	private function loadExactTransaction( OrderTransaction $transaction ) {
		$transaction_id = (int) ( $transaction->id ?? 0 );
		if ( $transaction_id <= 0 ) {
			return self::runtimeError( 'ys_helcim_purchase_transaction_invalid', 'The FluentCart transaction ID is invalid.' );
		}

		$loaded = ( $this->transaction_loader )( $transaction_id );
		return $loaded instanceof OrderTransaction && (int) $loaded->id === $transaction_id
			? $loaded
			: self::runtimeError( 'ys_helcim_purchase_transaction_missing', 'The exact FluentCart transaction could not be loaded.' );
	}

	/** @param array<string, int|string> $identity @return OrderTransaction|\WP_Error */
	private function loadForIdentity( array $identity ) {
		$loaded = ( $this->transaction_loader )( (int) ( $identity['transaction_id'] ?? 0 ) );
		if ( ! $loaded instanceof OrderTransaction ) {
			return self::runtimeError( 'ys_helcim_purchase_transaction_missing', 'The exact FluentCart transaction could not be loaded.' );
		}

		$current = $this->identity( $loaded );
		if ( is_wp_error( $current ) || $current !== $identity ) {
			return self::runtimeError( 'ys_helcim_purchase_identity_changed', 'The FluentCart transaction identity changed during payment.' );
		}

		return $loaded;
	}

	/** @return array<string, int|string>|\WP_Error */
	private function identity( OrderTransaction $transaction ) {
		if (
			$this->method_slug !== (string) ( $transaction->payment_method ?? '' ) ||
			Status::TRANSACTION_TYPE_CHARGE !== (string) ( $transaction->transaction_type ?? '' )
		) {
			return self::runtimeError( 'ys_helcim_purchase_transaction_mismatch', 'The FluentCart transaction is not an inline Helcim charge.' );
		}

		$operation = YSHelcimPurchaseOperation::fromTransaction(
			array(
				'gateway'          => $this->method_slug,
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

	/** @param array<string, int|string> $identity @return true|\WP_Error */
	private function preflight( array $identity ) {
		$credential = $this->credentialForIdentity( $identity );
		if ( is_wp_error( $credential ) ) {
			return $credential;
		}
		$credential = '';

		$order_state = $this->orderState( $identity );
		if ( is_wp_error( $order_state ) ) {
			return $order_state;
		}
		if ( 'unpaid' !== $order_state['status'] ) {
			return self::runtimeError( 'ys_helcim_purchase_order_state_unsafe', 'The order is not in a safe unpaid state.' );
		}

		return true;
	}

	/** @param array<string, int|string> $identity @return string|\WP_Error */
	private function credentialForIdentity( array $identity ) {
		$mode      = (string) ( $identity['payment_mode'] ?? '' );
		$credential = $this->settings->getApiTokenForMode( $mode );
		if ( '' === trim( $credential ) ) {
			return self::runtimeError( 'ys_helcim_purchase_credential_missing', 'The Helcim API credential for this transaction mode is unavailable.' );
		}

		return $credential;
	}

	/** @return array<string, mixed> */
	private function existingSuccess( string $provider_id ): array {
		return array(
			'status'                  => YSHelcimPurchaseCoordinator::SUCCEEDED,
			'operation_uuid'          => '',
			'remote_status'           => 'succeeded',
			'local_status'            => 'applied',
			'provider_transaction_id' => $provider_id,
			'error_code'              => null,
			'replayed'                => true,
		);
	}

	/**
	 * @param array<string, int|string> $identity
	 * @return array{status:string,order:Order}|\WP_Error
	 */
	private function orderState( array $identity ) {
		$order = ( $this->order_loader )( (int) ( $identity['order_id'] ?? 0 ) );
		if ( ! $order instanceof Order || (int) $order->id !== (int) ( $identity['order_id'] ?? 0 ) ) {
			return self::runtimeError( 'ys_helcim_purchase_order_missing', 'The exact FluentCart order could not be loaded.' );
		}

		$total_amount = (int) ( $order->total_amount ?? 0 );
		$total_paid   = (int) ( $order->total_paid ?? 0 );
		$payment      = strtolower( trim( (string) ( $order->payment_status ?? '' ) ) );
		if ( $total_amount !== (int) ( $identity['amount'] ?? 0 ) || $total_paid < 0 || $total_paid > $total_amount ) {
			return array( 'status' => 'unknown', 'order' => $order );
		}
		if ( 'paid' === $payment && $total_paid === $total_amount ) {
			return array( 'status' => 'paid', 'order' => $order );
		}
		if ( in_array( $payment, array( 'pending', 'failed' ), true ) && 0 === $total_paid ) {
			return array( 'status' => 'unpaid', 'order' => $order );
		}

		return array( 'status' => 'unknown', 'order' => $order );
	}

	/** @param array<string, int|string> $identity @return array<string, mixed> */
	private static function invoice( array $identity, string $operation_uuid, string $amount ): array {
		return array(
			'invoiceNumber' => $operation_uuid,
			'lineItems'     => array(
				array(
					'sku'         => 'YSFC-' . (int) $identity['transaction_id'],
					'description' => 'FluentCart order ' . (int) $identity['order_id'],
					'quantity'    => 1,
					'price'       => $amount,
				),
			),
		);
	}

	/** @return array<string, string> */
	private static function billingAddress( Order $order ): array {
		$billing  = $order->billing_address;
		$shipping = $order->shipping_address;
		if ( ! $billing && ! $shipping ) {
			return array();
		}

		$pick = static function ( string $attribute ) use ( $billing, $shipping ): string {
			$value = $billing ? (string) ( $billing->{$attribute} ?? '' ) : '';
			return '' === $value && $shipping ? (string) ( $shipping->{$attribute} ?? '' ) : $value;
		};
		$email = $pick( 'email' );
		if ( '' === $email && $order->customer ) {
			$email = (string) ( $order->customer->email ?? '' );
		}

		return array_filter(
			array(
				'name'       => $pick( 'name' ),
				'street1'    => $pick( 'address_1' ),
				'street2'    => $pick( 'address_2' ),
				'city'       => $pick( 'city' ),
				'province'   => $pick( 'state' ),
				'postalCode' => $pick( 'postcode' ),
				'country'    => self::countryCode( $pick( 'country' ) ),
				'phone'      => $pick( 'phone' ),
				'email'      => $email,
			),
			static fn ( string $value ): bool => '' !== $value
		);
	}

	private static function countryCode( string $alpha2 ): string {
		$alpha2 = strtoupper( trim( $alpha2 ) );
		return array(
			'TW' => 'TWN',
			'US' => 'USA',
			'CA' => 'CAN',
			'GB' => 'GBR',
			'AU' => 'AUS',
			'JP' => 'JPN',
			'CN' => 'CHN',
			'HK' => 'HKG',
			'SG' => 'SGP',
		)[ $alpha2 ] ?? $alpha2;
	}

	private static function clientIp(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';
		return '' !== $ip && false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '127.0.0.1';
	}

	private static function decimalAmount( int $amount_cents ): string {
		return number_format( $amount_cents / 100, 2, '.', '' );
	}

	private static function isEmptyProviderId( mixed $provider_id ): bool {
		return null === $provider_id || '' === trim( (string) $provider_id );
	}

	/** @return array<string, mixed> */
	private static function attentionResult( string $error_code ): array {
		return array(
			'status'                  => YSHelcimPurchaseCoordinator::ATTENTION_REQUIRED,
			'operation_uuid'          => '',
			'remote_status'           => 'indeterminate',
			'local_status'            => 'pending',
			'provider_transaction_id' => null,
			'error_code'              => $error_code,
			'replayed'                => true,
		);
	}

	private static function runtimeError( string $code, string $message ): \WP_Error {
		return new \WP_Error(
			$code,
			__( $message, 'ys-helcim-via-fluentcart' )
		);
	}
}
