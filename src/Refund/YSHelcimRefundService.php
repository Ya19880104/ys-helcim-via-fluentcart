<?php
/**
 * Durable remote-first Helcim refund and reverse coordination.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Refund;

use YangSheep\Helcim\FluentCart\Operations\YSHelcimIdempotency;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationRepository;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationState;
use YangSheep\Helcim\FluentCart\Security\YSHelcimSensitiveEnvelope;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends at most one provider mutation for a claimed journal operation.
 */
final class YSHelcimRefundService {

	/** @var callable */
	private $api_request;

	/** @var callable */
	private $uuid_factory;

	/** @var callable */
	private $clock;

	/**
	 * @param YSHelcimOperationRepository $operations   Durable operation repository.
	 * @param callable                    $api_request  Helcim API request callable.
	 * @param callable                    $uuid_factory Persistent UUID generator for reverse children.
	 * @param callable|null               $clock        UTC SQL timestamp provider.
	 */
	public function __construct(
		private YSHelcimOperationRepository $operations,
		callable $api_request,
		callable $uuid_factory,
		?callable $clock = null
	) {
		$this->api_request  = $api_request;
		$this->uuid_factory = $uuid_factory;
		$this->clock        = $clock ?? static fn (): string => gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Execute or safely resume one refund operation.
	 *
	 * This method never records a FluentCart refund. The local recorder may run
	 * only after this service returns a journal-backed success.
	 *
	 * @param array<string, mixed> $request Validated transaction and provider context.
	 * @return YSHelcimRefundResult|\WP_Error
	 */
	public function execute( array $request ) {
		$identity = $this->validateIdentity( $request );
		if ( is_wp_error( $identity ) ) {
			return $identity;
		}

		$refund_uuid = $identity['operation_uuid'];
		$fingerprint = self::fingerprint( $identity, 'refund', null );
		$existing    = $this->operations->findByUuid( $refund_uuid );
		if ( is_array( $existing ) && YSHelcimOperationState::REMOTE_PROCESSING === ( $existing['remote_status'] ?? null ) ) {
			$promoted = $this->operations->promoteStaleRefundProcessing( $refund_uuid );
			if ( is_wp_error( $promoted ) ) {
				return $promoted;
			}
			if ( 1 === $promoted ) {
				$existing = $this->operations->findByUuid( $refund_uuid );
				if ( null === $existing ) {
					return self::operationConflict();
				}
			}
		}

		if ( null === $existing ) {
			$mutation = $this->validateNewMutation( $identity, $request );
			if ( is_wp_error( $mutation ) ) {
				return $mutation;
			}

			try {
				$idempotency_key = YSHelcimIdempotency::generate(
					'refund',
					$identity['transaction_uuid'],
					$identity['amount'],
					$identity['payment_mode'],
					$refund_uuid
				);
				$material = $this->encryptMutationContext( $mutation );
			} catch ( \Throwable $exception ) {
				unset( $exception );
				return self::invalidRequest();
			}

			$existing = $this->operations->create(
				array(
					'operation_uuid'     => $refund_uuid,
					'idempotency_key'    => $idempotency_key,
					'scope_key'           => 'refund-order:' . $identity['order_id'],
					'operation_type'      => 'refund',
					'gateway'             => $identity['gateway'],
					'order_id'            => $identity['order_id'],
					'transaction_id'      => $identity['transaction_id'],
					'transaction_uuid'    => $identity['transaction_uuid'],
					'amount'              => $identity['amount'],
					'currency'            => $identity['currency'],
					'payment_mode'        => $identity['payment_mode'],
					'source_vendor_transaction_id' => $identity['vendor_transaction_id'],
					'request_fingerprint' => $fingerprint,
					'local_payload'        => $identity['local_payload_json'],
					'local_payload_hash'   => $identity['local_payload_hash'],
					'encrypted_material'  => $material['envelope'],
					'material_expires_at' => $material['expires_at'],
				)
			);
			if ( is_wp_error( $existing ) ) {
				return $existing;
			}
		} elseif ( ! $this->rowMatchesRequest( $existing, $identity, 'refund', $fingerprint ) ) {
			return self::operationConflict();
		} else {
			$mutation = null;
		}

		$reverse = $this->operations->findChildByParent( $refund_uuid, 'reverse' );
		if ( null !== $reverse ) {
			if ( YSHelcimOperationState::REMOTE_PROCESSING === ( $reverse['remote_status'] ?? null ) ) {
				$promoted = $this->operations->promoteStaleRefundProcessing( (string) $reverse['operation_uuid'] );
				if ( is_wp_error( $promoted ) ) {
					return $promoted;
				}
				if ( 1 === $promoted ) {
					$reverse = $this->operations->findByUuid( (string) $reverse['operation_uuid'] );
					if ( null === $reverse ) {
						return self::operationConflict();
					}
				}
			}
			$child_fingerprint = self::fingerprint( $identity, 'reverse', $refund_uuid );
			if ( ! $this->rowMatchesRequest( $reverse, $identity, 'reverse', $child_fingerprint ) ) {
				return self::operationConflict();
			}

			if ( YSHelcimOperationState::REMOTE_CREATED !== $reverse['remote_status'] ) {
				return $this->resultFromRow( $reverse, $refund_uuid );
			}

			$mutation = $this->restoreMutationContext( $reverse, $identity, $request );
			return is_wp_error( $mutation ) ? $mutation : $this->processRow( $reverse, $mutation, $refund_uuid );
		}

		if ( YSHelcimOperationState::REMOTE_CREATED !== $existing['remote_status'] ) {
			return $this->resultFromRow( $existing, $refund_uuid );
		}

		if ( null === $mutation ) {
			$mutation = $this->restoreMutationContext( $existing, $identity, $request );
		}

		return is_wp_error( $mutation ) ? $mutation : $this->processRow( $existing, $mutation, $refund_uuid );
	}

	/** @return YSHelcimRefundResult|\WP_Error */
	private function processRow( array $row, array $request, string $refund_uuid ) {
		if ( YSHelcimOperationState::REMOTE_CREATED !== $row['remote_status'] ) {
			return $this->resultFromRow( $row, $refund_uuid );
		}

		$claimed = $this->operations->claimRemoteProcessing( (string) $row['operation_uuid'] );
		if ( is_wp_error( $claimed ) ) {
			return $claimed;
		}
		if ( true !== $claimed ) {
			$current = $this->operations->findByUuid( (string) $row['operation_uuid'] );
			return null === $current ? self::operationConflict() : $this->resultFromRow( $current, $refund_uuid );
		}

		$operation_type = (string) $row['operation_type'];
		$payload        = 'refund' === $operation_type
			? array(
				'originalTransactionId' => (int) $request['vendor_transaction_id'],
				'amount'                => self::formatCents( $request['amount'] ),
				'ipAddress'             => $request['ip_address'],
			)
			: array(
				'cardTransactionId' => (int) $request['vendor_transaction_id'],
				'ipAddress'         => $request['ip_address'],
			);

		try {
			$response = ( $this->api_request )(
				'payment/' . $operation_type,
				$payload,
				$request['api_token'],
				(string) $row['idempotency_key'],
				'POST'
			);
		} catch ( \Throwable $exception ) {
			$result = new YSHelcimRefundResult(
				YSHelcimRefundResult::INDETERMINATE,
				null,
				'provider_exception',
				'The provider request ended without a verifiable response.'
			);
			unset( $exception );
			return $this->persistResult( $row, $result, $refund_uuid );
		}

		$result = YSHelcimProviderProof::classify(
			$response,
			$operation_type,
			(int) $row['amount'],
			(string) $row['currency']
		);

		if ( 'refund' === $operation_type && YSHelcimRefundResult::REQUIRES_REVERSE === $result->status() ) {
			return $this->attemptVerifiedReverse( $row, $request, $refund_uuid );
		}

		return $this->persistResult( $row, $result, $refund_uuid );
	}

	/** @return YSHelcimRefundResult|\WP_Error */
	private function attemptVerifiedReverse( array $refund_row, array $request, string $refund_uuid ) {
		$is_full_unrefunded_charge = 0 === $request['refunded_total']
			&& $request['amount'] === $request['transaction_total']
			&& $request['remaining_refundable'] === $request['transaction_total'];

		if ( ! $is_full_unrefunded_charge ) {
			return $this->persistResult(
				$refund_row,
				new YSHelcimRefundResult(
					YSHelcimRefundResult::FAILED,
					null,
					'open_batch_partial_refund_unsupported',
					'An open-batch payment can only be reversed in full.'
				),
				$refund_uuid
			);
		}

		$source_id = $request['vendor_transaction_id'];
		$source    = $this->readProvider(
			'card-transactions/' . rawurlencode( $source_id ),
			$request['api_token']
		);
		$source = self::unwrapData( $source );
		if ( ! $this->isExactSourceTransaction( $source, $request ) ) {
			return $this->failUnprovenReverse( $refund_row, $refund_uuid );
		}

		$batch_id = self::positiveIntegerString( $source['cardBatchId'] ?? null );
		$batch    = $this->readProvider(
			'card-batches/' . rawurlencode( (string) $batch_id ),
			$request['api_token']
		);
		$batch = self::unwrapData( $batch );
		if (
			! is_array( $batch ) ||
			$batch_id !== self::positiveIntegerString( $batch['id'] ?? null ) ||
			! array_key_exists( 'closed', $batch ) ||
			false !== $batch['closed']
		) {
			return $this->failUnprovenReverse( $refund_row, $refund_uuid );
		}

		$child_uuid = strtolower( trim( (string) ( $this->uuid_factory )() ) );
		try {
			$child_key = YSHelcimIdempotency::generate(
				'reverse',
				$request['transaction_uuid'],
				$request['amount'],
				$request['payment_mode'],
				$child_uuid
			);
			$child_material = $this->encryptMutationContext( $request );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::invalidRequest();
		}

		$child = array(
			'operation_uuid'         => $child_uuid,
			'idempotency_key'        => $child_key,
			'scope_key'               => 'refund-order:' . $request['order_id'],
			'operation_type'          => 'reverse',
			'gateway'                 => $request['gateway'],
			'order_id'                => $request['order_id'],
			'transaction_id'          => $request['transaction_id'],
			'transaction_uuid'        => $request['transaction_uuid'],
			'parent_operation_uuid'   => $refund_uuid,
			'amount'                  => $request['amount'],
			'currency'                => $request['currency'],
			'payment_mode'            => $request['payment_mode'],
			'source_vendor_transaction_id' => $request['vendor_transaction_id'],
			'request_fingerprint'     => self::fingerprint( $request, 'reverse', $refund_uuid ),
			'local_payload'            => $request['local_payload_json'],
			'local_payload_hash'       => $request['local_payload_hash'],
			'encrypted_material'      => $child_material['envelope'],
			'material_expires_at'     => $child_material['expires_at'],
		);

		$created = $this->operations->handoffRemoteFailureToChild(
			(string) $refund_row['operation_uuid'],
			YSHelcimOperationState::REMOTE_PROCESSING,
			$child,
			array(
				'error_code'    => 'open_batch_verified_reverse',
				'error_message' => 'Refund was rejected and the original transaction was verified in an open batch.',
			)
		);
		if ( is_wp_error( $created ) ) {
			return $created;
		}

		return $this->processRow( $created, $request, $refund_uuid );
	}

	private function isExactSourceTransaction( mixed $source, array $request ): bool {
		if ( ! is_array( $source ) ) {
			return false;
		}

		return $request['vendor_transaction_id'] === self::positiveIntegerString( $source['transactionId'] ?? null )
			&& 'APPROVED' === strtoupper( trim( (string) ( $source['status'] ?? '' ) ) )
			&& in_array( strtolower( trim( (string) ( $source['type'] ?? '' ) ) ), array( 'purchase', 'capture' ), true )
			&& $request['transaction_total'] === YSHelcimProviderProof::amountToCents( $source['amount'] ?? null )
			&& $request['currency'] === strtoupper( trim( (string) ( $source['currency'] ?? '' ) ) )
			&& null !== self::positiveIntegerString( $source['cardBatchId'] ?? null );
	}

	/** @return YSHelcimRefundResult */
	private function failUnprovenReverse( array $refund_row, string $refund_uuid ) {
		return $this->persistResult(
			$refund_row,
			new YSHelcimRefundResult(
				YSHelcimRefundResult::FAILED,
				null,
				'open_batch_unproven',
				'The original transaction and open batch could not be proven exactly; no reversal was sent.'
			),
			$refund_uuid
		);
	}

	/** @return YSHelcimRefundResult */
	private function persistResult( array $row, YSHelcimRefundResult $result, string $refund_uuid ): YSHelcimRefundResult {
		$state = array(
			YSHelcimRefundResult::SUCCEEDED     => YSHelcimOperationState::REMOTE_SUCCEEDED,
			YSHelcimRefundResult::DECLINED      => YSHelcimOperationState::REMOTE_DECLINED,
			YSHelcimRefundResult::FAILED        => YSHelcimOperationState::REMOTE_FAILED,
			YSHelcimRefundResult::INDETERMINATE => YSHelcimOperationState::REMOTE_INDETERMINATE,
		)[ $result->status() ] ?? null;

		if ( null === $state ) {
			return new YSHelcimRefundResult(
				YSHelcimRefundResult::FAILED,
				null,
				'unsupported_provider_directive',
				'The provider result could not be applied safely.',
				$refund_uuid,
				(string) $row['operation_uuid'],
				(string) $row['operation_type']
			);
		}

		$changes = array(
			'error_code'    => $result->errorCode(),
			'error_message' => $result->message(),
		);
		if ( YSHelcimRefundResult::SUCCEEDED === $result->status() ) {
			$changes['vendor_transaction_id'] = $result->vendorTransactionId();
		}

		$transition = $this->operations->transitionRemote(
			(string) $row['operation_uuid'],
			YSHelcimOperationState::REMOTE_PROCESSING,
			$state,
			$changes
		);
		if ( true === $transition ) {
			return $result->withOperationContext(
				$refund_uuid,
				(string) $row['operation_uuid'],
				(string) $row['operation_type']
			);
		}

		$current = $this->operations->findByUuid( (string) $row['operation_uuid'] );
		if ( null !== $current && YSHelcimOperationState::REMOTE_PROCESSING !== $current['remote_status'] ) {
			return $this->resultFromRow( $current, $refund_uuid );
		}

		return new YSHelcimRefundResult(
			YSHelcimRefundResult::INDETERMINATE,
			null,
			'journal_outcome_unpersisted',
			'The provider responded, but the durable result could not be recorded. Reconciliation is required.',
			$refund_uuid,
			(string) $row['operation_uuid'],
			(string) $row['operation_type']
		);
	}

	private function resultFromRow( array $row, string $refund_uuid ): YSHelcimRefundResult {
		$status = (string) $row['remote_status'];
		$action = (string) $row['operation_type'];
		$uuid   = (string) $row['operation_uuid'];

		if ( YSHelcimOperationState::REMOTE_SUCCEEDED === $status ) {
			$vendor_id = self::positiveIntegerString( $row['vendor_transaction_id'] ?? null );
			if ( null !== $vendor_id ) {
				return new YSHelcimRefundResult(
					YSHelcimRefundResult::SUCCEEDED,
					$vendor_id,
					null,
					null,
					$refund_uuid,
					$uuid,
					$action
				);
			}
		}

		$mapped = array(
			YSHelcimOperationState::REMOTE_DECLINED      => YSHelcimRefundResult::DECLINED,
			YSHelcimOperationState::REMOTE_FAILED        => YSHelcimRefundResult::FAILED,
			YSHelcimOperationState::REMOTE_CANCELED      => YSHelcimRefundResult::FAILED,
			YSHelcimOperationState::REMOTE_EXPIRED       => YSHelcimRefundResult::FAILED,
			YSHelcimOperationState::REMOTE_PROCESSING    => YSHelcimRefundResult::INDETERMINATE,
			YSHelcimOperationState::REMOTE_INDETERMINATE => YSHelcimRefundResult::INDETERMINATE,
		)[ $status ] ?? YSHelcimRefundResult::INDETERMINATE;

		return new YSHelcimRefundResult(
			$mapped,
			null,
			(string) ( $row['remote_error_code'] ?? 'operation_unresolved' ),
			(string) ( $row['remote_error_message'] ?? 'The payment operation requires reconciliation.' ),
			$refund_uuid,
			$uuid,
			$action
		);
	}

	/** Validate immutable operation identity before reading a durable result. */
	private function validateIdentity( array $request ) {
		$required = array(
			'operation_uuid', 'gateway', 'order_id', 'transaction_id', 'transaction_uuid',
			'vendor_transaction_id', 'amount', 'transaction_total', 'currency', 'payment_mode',
		);
		foreach ( $required as $field ) {
			if ( ! array_key_exists( $field, $request ) ) {
				return self::invalidRequest();
			}
		}

		foreach ( array( 'order_id', 'transaction_id', 'amount', 'transaction_total' ) as $integer_field ) {
			if ( ! is_int( $request[ $integer_field ] ) ) {
				return self::invalidRequest();
			}
		}

		try {
			$local_payload      = YSHelcimRefundPayload::normalize(
				isset( $request['local_payload'] ) && is_array( $request['local_payload'] )
					? $request['local_payload']
					: array()
			);
			$local_payload_json = wp_json_encode( $local_payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( ! is_string( $local_payload_json ) ) {
				return self::invalidRequest();
			}
			$local_payload_hash = YSHelcimRefundPayload::hash( $local_payload );
		} catch ( \InvalidArgumentException $exception ) {
			unset( $exception );
			return self::invalidRequest();
		}

		$normalized = array(
			'operation_uuid'       => strtolower( trim( (string) $request['operation_uuid'] ) ),
			'gateway'              => trim( (string) $request['gateway'] ),
			'order_id'             => (int) $request['order_id'],
			'transaction_id'       => (int) $request['transaction_id'],
			'transaction_uuid'     => trim( (string) $request['transaction_uuid'] ),
			'vendor_transaction_id' => self::positiveIntegerString( $request['vendor_transaction_id'] ),
			'amount'               => (int) $request['amount'],
			'transaction_total'    => (int) $request['transaction_total'],
			'currency'             => strtoupper( trim( (string) $request['currency'] ) ),
			'payment_mode'         => strtolower( trim( (string) $request['payment_mode'] ) ),
			'local_payload'        => $local_payload,
			'local_payload_json'   => $local_payload_json,
			'local_payload_hash'   => $local_payload_hash,
		);

		if (
			1 !== preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $normalized['operation_uuid'] ) ||
			! in_array( $normalized['gateway'], array( 'ys_helcim', 'ys_helcim_js' ), true ) ||
			$normalized['order_id'] <= 0 ||
			$normalized['transaction_id'] <= 0 ||
			'' === $normalized['transaction_uuid'] ||
			null === $normalized['vendor_transaction_id'] ||
			$normalized['amount'] <= 0 ||
			$normalized['transaction_total'] <= 0 ||
			$normalized['amount'] > $normalized['transaction_total'] ||
			! in_array( $normalized['currency'], array( 'USD', 'CAD' ), true ) ||
			! in_array( $normalized['payment_mode'], array( 'test', 'live' ), true )
		) {
			return self::invalidRequest();
		}

		return $normalized;
	}

	/** Validate mutable freshness and provider context only for a new mutation. */
	private function validateNewMutation( array $identity, array $request ) {
		foreach ( array( 'refunded_total', 'remaining_refundable', 'current_mode', 'api_token', 'ip_address' ) as $field ) {
			if ( ! array_key_exists( $field, $request ) ) {
				return self::invalidRequest();
			}
		}
		foreach ( array( 'refunded_total', 'remaining_refundable' ) as $field ) {
			if ( ! is_int( $request[ $field ] ) ) {
				return self::invalidRequest();
			}
		}

		$mutation = array_merge(
			$identity,
			array(
				'refunded_total'       => $request['refunded_total'],
				'remaining_refundable' => $request['remaining_refundable'],
				'current_mode'         => strtolower( trim( (string) $request['current_mode'] ) ),
				'api_token'            => trim( (string) $request['api_token'] ),
				'ip_address'           => trim( (string) $request['ip_address'] ),
			)
		);

		if (
			$mutation['refunded_total'] < 0 ||
			$mutation['remaining_refundable'] < 0 ||
			$mutation['refunded_total'] > $identity['transaction_total'] ||
			$mutation['remaining_refundable'] > $identity['transaction_total'] - $mutation['refunded_total'] ||
			$identity['amount'] > $mutation['remaining_refundable'] ||
			$identity['payment_mode'] !== $mutation['current_mode'] ||
			'' === $mutation['api_token'] ||
			false === filter_var( $mutation['ip_address'], FILTER_VALIDATE_IP )
		) {
			return self::invalidRequest();
		}

		return $mutation;
	}

	/** Encrypt the exact mutation payload context required after a pre-send crash. */
	private function encryptMutationContext( array $mutation ): array {
		$plaintext = wp_json_encode(
			array(
				'version'                       => 1,
				'vendor_transaction_id'         => $mutation['vendor_transaction_id'],
				'transaction_total'              => $mutation['transaction_total'],
				'refunded_total'                 => $mutation['refunded_total'],
				'remaining_refundable'           => $mutation['remaining_refundable'],
				'ip_address'                     => $mutation['ip_address'],
				'credential_fingerprint'         => self::credentialFingerprint( $mutation['api_token'] ),
			),
			JSON_UNESCAPED_SLASHES
		);
		if ( ! is_string( $plaintext ) ) {
			throw new \RuntimeException( 'Refund mutation context could not be encoded.' );
		}

		$now = \DateTimeImmutable::createFromFormat(
			'!Y-m-d H:i:s',
			( $this->clock )(),
			new \DateTimeZone( 'UTC' )
		);
		if ( false === $now ) {
			throw new \RuntimeException( 'Refund mutation clock is invalid.' );
		}

		return array(
			'envelope'   => YSHelcimSensitiveEnvelope::encrypt( $plaintext ),
			'expires_at' => $now->modify( '+1 hour' )->format( 'Y-m-d H:i:s' ),
		);
	}

	/** Restore exact payload context while requiring the same credential identity. */
	private function restoreMutationContext( array $row, array $identity, array $request ) {
		$api_token = isset( $request['api_token'] ) ? trim( (string) $request['api_token'] ) : '';
		$expires   = (string) ( $row['material_expires_at'] ?? '' );
		$envelope  = (string) ( $row['encrypted_material'] ?? '' );
		if ( '' === $api_token || '' === $expires || $expires <= ( $this->clock )() || '' === $envelope ) {
			return self::operationConflict();
		}

		$plaintext = YSHelcimSensitiveEnvelope::decrypt( $envelope );
		$stored    = is_string( $plaintext ) ? json_decode( $plaintext, true ) : null;
		if (
			! is_array( $stored ) ||
			1 !== (int) ( $stored['version'] ?? 0 ) ||
			! hash_equals( (string) ( $stored['credential_fingerprint'] ?? '' ), self::credentialFingerprint( $api_token ) ) ||
			$identity['vendor_transaction_id'] !== self::positiveIntegerString( $stored['vendor_transaction_id'] ?? null ) ||
			$identity['transaction_total'] !== ( $stored['transaction_total'] ?? null ) ||
			! is_int( $stored['refunded_total'] ?? null ) ||
			! is_int( $stored['remaining_refundable'] ?? null ) ||
			! is_string( $stored['ip_address'] ?? null ) ||
			false === filter_var( $stored['ip_address'], FILTER_VALIDATE_IP )
		) {
			return self::operationConflict();
		}

		return array_merge(
			$identity,
			array(
				'refunded_total'       => $stored['refunded_total'],
				'remaining_refundable' => $stored['remaining_refundable'],
				'api_token'            => $api_token,
				'ip_address'           => $stored['ip_address'],
			)
		);
	}

	private function rowMatchesRequest( array $row, array $request, string $type, string $fingerprint ): bool {
		return $type === (string) $row['operation_type']
			&& $request['gateway'] === (string) $row['gateway']
			&& $request['order_id'] === (int) $row['order_id']
			&& $request['transaction_id'] === (int) $row['transaction_id']
			&& $request['transaction_uuid'] === (string) $row['transaction_uuid']
			&& $request['amount'] === (int) $row['amount']
			&& $request['currency'] === (string) $row['currency']
			&& $request['payment_mode'] === (string) $row['payment_mode']
			&& $request['local_payload_hash'] === (string) ( $row['local_payload_hash'] ?? '' )
			&& hash_equals( $fingerprint, (string) $row['request_fingerprint'] );
	}

	private static function fingerprint( array $request, string $operation_type, ?string $parent_uuid ): string {
		$material = wp_json_encode(
			array(
				'version'                       => 2,
				'operation_type'                => $operation_type,
				'parent_operation_uuid'         => $parent_uuid,
				'gateway'                       => $request['gateway'],
				'order_id'                      => $request['order_id'],
				'transaction_id'                => $request['transaction_id'],
				'transaction_uuid'              => $request['transaction_uuid'],
				'source_vendor_transaction_id'  => $request['vendor_transaction_id'],
				'amount'                        => $request['amount'],
				'transaction_total'             => $request['transaction_total'],
				'currency'                      => $request['currency'],
				'payment_mode'                  => $request['payment_mode'],
				'local_payload_hash'             => $request['local_payload_hash'],
			),
			JSON_UNESCAPED_SLASHES
		);

		return hash( 'sha256', is_string( $material ) ? $material : '' );
	}

	private static function unwrapData( mixed $response ): mixed {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return is_array( $response ) && isset( $response['data'] ) && is_array( $response['data'] )
			? $response['data']
			: $response;
	}

	/** Return an ambiguous read error instead of allowing a transport exception to escape. */
	private function readProvider( string $endpoint, string $api_token ): array|\WP_Error {
		try {
			return ( $this->api_request )( $endpoint, array(), $api_token, null, 'GET' );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return new \WP_Error(
				'ys_helcim_api_error',
				__( 'Helcim transaction verification could not be completed.', 'ys-helcim-via-fluentcart' ),
				array(
					'kind'          => 'transport',
					'indeterminate' => true,
				)
			);
		}
	}

	private static function positiveIntegerString( mixed $value ): ?string {
		if ( is_bool( $value ) || ( ! is_int( $value ) && ! is_string( $value ) ) ) {
			return null;
		}
		$value = (string) $value;
		if ( 1 !== preg_match( '/\A[1-9][0-9]*\z/', $value ) ) {
			return null;
		}
		$max = (string) PHP_INT_MAX;
		return strlen( $value ) < strlen( $max ) || ( strlen( $value ) === strlen( $max ) && strcmp( $value, $max ) <= 0 )
			? $value
			: null;
	}

	private static function credentialFingerprint( string $api_token ): string {
		return hash_hmac(
			'sha256',
			$api_token,
			wp_salt( 'auth' ) . '|ys-helcim-refund-credential-v1'
		);
	}

	private static function formatCents( int $amount ): string {
		return intdiv( $amount, 100 ) . '.' . str_pad( (string) ( $amount % 100 ), 2, '0', STR_PAD_LEFT );
	}

	private static function invalidRequest(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_invalid_refund',
			__( 'The Helcim refund request contains invalid or stale transaction data.', 'ys-helcim-via-fluentcart' )
		);
	}

	private static function operationConflict(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_operation_conflict',
			__( 'The refund operation changed or is already being processed.', 'ys-helcim-via-fluentcart' )
		);
	}
}
