<?php
/**
 * Exact purchase reconciliation for Helcim card-transaction webhooks.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Webhook;

use YangSheep\Helcim\FluentCart\HelcimJs\YSHelcimJsPurchaseResponseAdapter;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationState;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimPurchaseOperation;
use YangSheep\Helcim\FluentCart\Support\YSHelcimDatabaseInteger;
use YangSheep\Helcim\FluentCart\Support\YSHelcimTransactionId;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Selects a purchase only by the operation UUID carried by its Helcim invoice.
 */
final class YSHelcimWebhookPurchaseReconciler {

	/** @var callable */
	private $operation_reader;

	/** @var callable */
	private $transaction_loader;

	/** @var callable */
	private $runtime_resolver;

	public function __construct(
		callable $operation_reader,
		callable $transaction_loader,
		callable $runtime_resolver
	) {
		$this->operation_reader   = $operation_reader;
		$this->transaction_loader = $transaction_loader;
		$this->runtime_resolver   = $runtime_resolver;
	}

	/**
	 * @param array<string,mixed>                         $proof    Provider-owned GET proof.
	 * @param array<int,array{gateway:string,mode:string}> $bindings Verified account bindings.
	 * @return array{code:int,message:string}
	 */
	public function reconcile( array $proof, string $transaction_id, array $bindings ): array {
		$proved_id = YSHelcimTransactionId::normalize( $proof['transactionId'] ?? null );
		$event_id  = YSHelcimTransactionId::normalize( $transaction_id );
		if ( null === $proved_id || null === $event_id || ! hash_equals( $event_id, $proved_id ) ) {
			return self::response( 400, 'transaction proof mismatch' );
		}

		$operation_uuid = self::uuid( $proof['invoiceNumber'] ?? null );
		if ( null === $operation_uuid ) {
			return self::response( 409, 'operation correlation unavailable' );
		}

		try {
			$row = ( $this->operation_reader )( $operation_uuid );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::response( 503, 'operation journal unavailable' );
		}
		if ( is_wp_error( $row ) ) {
			return self::response( 503, 'operation journal unavailable' );
		}
		if ( ! is_array( $row ) || ! hash_equals( $operation_uuid, strtolower( (string) ( $row['operation_uuid'] ?? '' ) ) ) ) {
			return self::response( 409, 'operation correlation unavailable' );
		}

		$identity = self::identity( $row );
		$operation = is_array( $identity ) ? YSHelcimPurchaseOperation::fromTransaction( $identity ) : null;
		if (
			! $operation instanceof YSHelcimPurchaseOperation ||
			! $operation->matchesIdentityRow( $row ) ||
			! in_array(
				(string) ( $row['remote_status'] ?? '' ),
				array(
					YSHelcimOperationState::REMOTE_PROCESSING,
					YSHelcimOperationState::REMOTE_INDETERMINATE,
					YSHelcimOperationState::REMOTE_SUCCEEDED,
					YSHelcimOperationState::REMOTE_DECLINED,
				),
				true
			)
		) {
			return self::response( 409, 'operation state conflict' );
		}

		if ( ! self::hasBinding( $bindings, (string) $identity['gateway'], (string) $identity['payment_mode'] ) ) {
			return self::response( 409, 'credential binding mismatch' );
		}

		$provider_outcome = YSHelcimJsPurchaseResponseAdapter::toCoordinatorOutcome( $proof, $identity );
		if ( is_wp_error( $provider_outcome ) ) {
			return self::response( 422, 'purchase proof is not definitive' );
		}

		try {
			$transaction = ( $this->transaction_loader )( (int) $identity['transaction_id'] );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$transaction = null;
		}
		if ( ! is_object( $transaction ) || (int) ( $transaction->id ?? 0 ) !== (int) $identity['transaction_id'] ) {
			return self::response( 503, 'local transaction unavailable' );
		}

		try {
			$runtime = ( $this->runtime_resolver )( (string) $identity['gateway'], (string) $identity['payment_mode'] );
			$result  = is_object( $runtime ) && method_exists( $runtime, 'reconcileProviderProof' )
				? $runtime->reconcileProviderProof(
					$transaction,
					$operation_uuid,
					array( 'operation_correlation' => $operation_uuid ) + $provider_outcome
				)
				: null;
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$result = null;
		}
		if ( is_wp_error( $result ) || ! is_array( $result ) ) {
			return self::response( 503, 'local payment reconciliation failed' );
		}

		$status = (string) ( $result['status'] ?? '' );
		if ( in_array( $status, array( 'succeeded', 'declined' ), true ) ) {
			return self::response( 200, 'payment reconciled' );
		}
		if (
			YSHelcimOperationState::REMOTE_SUCCEEDED === (string) ( $result['remote_status'] ?? '' ) &&
			YSHelcimOperationState::LOCAL_APPLIED !== (string) ( $result['local_status'] ?? '' )
		) {
			return self::response( 503, 'local payment reconciliation incomplete' );
		}
		if (
			in_array(
				(string) ( $result['remote_status'] ?? '' ),
				array(
					YSHelcimOperationState::REMOTE_PROCESSING,
					YSHelcimOperationState::REMOTE_INDETERMINATE,
				),
				true
			)
		) {
			return self::response( 503, 'payment reconciliation incomplete' );
		}

		return self::response( 409, 'payment outcome requires review' );
	}

	/** @return array<string,int|string>|null */
	private static function identity( array $row ): ?array {
		$identity = array(
			'gateway'          => $row['gateway'] ?? null,
			'order_id'         => YSHelcimDatabaseInteger::positive( $row['order_id'] ?? null ),
			'transaction_id'   => YSHelcimDatabaseInteger::positive( $row['transaction_id'] ?? null ),
			'transaction_uuid' => $row['transaction_uuid'] ?? null,
			'amount'           => YSHelcimDatabaseInteger::positive( $row['amount'] ?? null ),
			'currency'         => $row['currency'] ?? null,
			'payment_mode'     => $row['payment_mode'] ?? null,
		);
		return is_string( $identity['gateway'] ) &&
			is_int( $identity['order_id'] ) &&
			is_int( $identity['transaction_id'] ) &&
			is_string( $identity['transaction_uuid'] ) &&
			is_int( $identity['amount'] ) &&
			is_string( $identity['currency'] ) &&
			is_string( $identity['payment_mode'] )
			? $identity
			: null;
	}

	/** @param array<int,mixed> $bindings */
	private static function hasBinding( array $bindings, string $gateway, string $mode ): bool {
		$matches = 0;
		foreach ( $bindings as $binding ) {
			if (
				is_array( $binding ) &&
				array( 'gateway', 'mode' ) === array_keys( $binding ) &&
				$gateway === $binding['gateway'] &&
				$mode === $binding['mode']
			) {
				++$matches;
			}
		}

		return 1 === $matches;
	}

	private static function uuid( mixed $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = strtolower( trim( $value ) );
		return 1 === preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value )
			? $value
			: null;
	}

	/** @return array{code:int,message:string} */
	private static function response( int $code, string $message ): array {
		return array( 'code' => $code, 'message' => $message );
	}
}
