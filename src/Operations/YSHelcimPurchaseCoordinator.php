<?php
/**
 * Durable, at-most-once Helcim purchase coordination.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Operations;

use YangSheep\Helcim\FluentCart\Support\YSHelcimPurchaseProof;
use YangSheep\Helcim\FluentCart\Support\YSHelcimTransactionId;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Claims a purchase before provider mutation and binds only durable exact proof.
 */
final class YSHelcimPurchaseCoordinator {
	private const LOCAL_CLAIM_TTL_SECONDS = 300;

	public const SUCCEEDED          = 'succeeded';
	public const DECLINED           = 'declined';
	public const FAILED             = 'failed';
	public const INDETERMINATE      = 'indeterminate';
	public const ATTENTION_REQUIRED = 'attention_required';

	/** @var callable */
	private $provider_purchase;

	/** @var callable */
	private $local_binder;

	/** @var callable */
	private $local_inspector;

	/** @var callable */
	private $uuid_factory;

	/** @var callable */
	private $clock;

	/**
	 * Provider callback contract:
	 * `(identity, ephemeral_card_token, persisted_idempotency_key, operation_uuid) -> array|WP_Error`.
	 * The operation UUID is the explicit correlation that adapters may place in
	 * a nested provider invoice custom-number field. The FluentCart UUID must
	 * never be sent as Helcim's top-level `invoiceNumber`.
	 *
	 * Local binder callback contract (must be exact-ID idempotent):
	 * `(identity, exact_provider_transaction_id, operation_uuid) -> array|WP_Error`.
	 * It must atomically save the exact ID and return
	 * `['bound' => true, 'provider_transaction_id' => exact_id]`.
	 *
	 * Inspector callback contract:
	 * `(identity, exact_provider_transaction_id, operation_uuid) -> array|WP_Error`.
	 * It must return exactly one of `bound`, `partial`, `unbound`, or `mismatch`
	 * plus the exact observed provider ID (null only for `unbound`). `partial`
	 * means the exact transaction ID is durable but another required local
	 * effect (for example the paid order state) is not yet proven; the binder is
	 * retried idempotently. Unknown is never interpreted as unbound.
	 */
	public function __construct(
		private YSHelcimOperationRepository $operations,
		callable $provider_purchase,
		callable $local_binder,
		callable $local_inspector,
		callable $uuid_factory,
		?callable $clock = null
	) {
		$this->provider_purchase = $provider_purchase;
		$this->local_binder      = $local_binder;
		$this->local_inspector   = $local_inspector;
		$this->uuid_factory      = $uuid_factory;
		$this->clock             = $clock ?? static fn (): string => gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Execute or replay one purchase identified only by server-loaded FC fields.
	 *
	 * The token is deliberately a separate ephemeral argument. It is used only
	 * by the unique remote claimant and is never copied into an operation row.
	 *
	 * @param array<string, mixed> $transaction                      Server-owned identity.
	 * @param string               $card_token                       Ephemeral provider token.
	 * @param string|null          $incoming_provider_transaction_id Optional replay proof.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute(
		array $transaction,
		string $card_token,
		?string $incoming_provider_transaction_id = null
	) {
		$operation = YSHelcimPurchaseOperation::fromTransaction( $transaction );
		if ( is_wp_error( $operation ) ) {
			return $operation;
		}

		$incoming_id = null;
		if ( null !== $incoming_provider_transaction_id ) {
			$incoming_id = YSHelcimTransactionId::normalize( $incoming_provider_transaction_id );
			if ( null === $incoming_id ) {
				return self::invalidProviderId();
			}
		}

		$identity = $operation->identity();
		$attempt_digest = self::isUsableCardToken( $card_token )
			? self::attemptDigest( $card_token )
			: null;
		$expired = $this->operations->expireStaleCreatedScope( $operation->scopeKey() );
		if ( is_wp_error( $expired ) ) {
			return $expired;
		}
		$attempts = $this->operations->findPurchasesByIdentity(
			(int) $identity['transaction_id']
		);
		if ( is_wp_error( $attempts ) ) {
			return $attempts;
		}

		foreach ( $attempts as $attempt ) {
			if ( ! is_array( $attempt ) || ! $operation->matchesIdentityRow( $attempt ) ) {
				return self::result(
					is_array( $attempt ) ? $attempt : array(),
					self::ATTENTION_REQUIRED,
					'operation_identity_mismatch',
					true
				);
			}
		}

		$existing = null;
		if ( null !== $attempt_digest ) {
			$matching_attempts = array_values(
				array_filter(
					$attempts,
					static fn ( array $attempt ): bool => $operation->matchesRow( $attempt, $attempt_digest )
				)
			);
			if ( count( $matching_attempts ) > 1 ) {
				return self::result( $matching_attempts[0], self::ATTENTION_REQUIRED, 'duplicate_attempt_identity', true );
			}
			$existing = $matching_attempts[0] ?? null;
		} elseif ( null !== $incoming_id ) {
			$matching_attempts = array_values(
				array_filter(
					$attempts,
					static fn ( array $attempt ): bool =>
						$incoming_id === YSHelcimTransactionId::normalize( $attempt['vendor_transaction_id'] ?? null )
				)
			);
			if ( count( $matching_attempts ) > 1 ) {
				return self::result( $matching_attempts[0], self::ATTENTION_REQUIRED, 'duplicate_provider_id', true );
			}
			$existing = $matching_attempts[0] ?? null;
		} elseif ( 1 === count( $attempts ) ) {
			$existing = $attempts[0];
		} elseif ( count( $attempts ) > 1 ) {
			return self::result( $attempts[ count( $attempts ) - 1 ], self::ATTENTION_REQUIRED, 'attempt_identity_required', true );
		}

		$replayed = is_array( $existing );
		if ( null === $existing ) {
			if ( null === $attempt_digest ) {
				if ( empty( $attempts ) ) {
					return self::invalidCardToken();
				}
				if ( null !== $incoming_id ) {
					return self::result(
						$attempts[ count( $attempts ) - 1 ],
						self::ATTENTION_REQUIRED,
						'provider_id_mismatch',
						true
					);
				}
				return self::operationConflict();
			}

			if ( null !== $incoming_id ) {
				return empty( $attempts )
					? self::operationConflict()
					: self::result( $attempts[ count( $attempts ) - 1 ], self::ATTENTION_REQUIRED, 'attempt_identity_mismatch', true );
			}

			foreach ( $attempts as $attempt ) {
				$remote_status = (string) $attempt['remote_status'];
				if ( YSHelcimOperationState::REMOTE_SUCCEEDED === $remote_status ) {
					return self::result( $attempt, self::ATTENTION_REQUIRED, 'transaction_already_paid', true );
				}
				if (
					! in_array(
						$remote_status,
						array(
							YSHelcimOperationState::REMOTE_DECLINED,
							YSHelcimOperationState::REMOTE_FAILED,
							YSHelcimOperationState::REMOTE_CANCELED,
							YSHelcimOperationState::REMOTE_EXPIRED,
						),
						true
					)
				) {
					return self::result( $attempt, self::ATTENTION_REQUIRED, 'purchase_attempt_in_progress', true );
				}
			}

			try {
				$operation_uuid = strtolower( trim( (string) ( $this->uuid_factory )() ) );
			} catch ( \Throwable $exception ) {
				unset( $exception );
				return self::invalidOperationUuid();
			}

			$record = $operation->repositoryRecord( $operation_uuid, $attempt_digest );
			if ( is_wp_error( $record ) ) {
				return $record;
			}

			$existing = $this->operations->create( $record );
			if ( is_wp_error( $existing ) ) {
				if ( 'ys_helcim_scope_busy' !== $existing->get_error_code() ) {
					return $existing;
				}

				$attempts = $this->operations->findPurchasesByIdentity(
					(int) $identity['transaction_id']
				);
				if ( is_wp_error( $attempts ) ) {
					return $attempts;
				}
				foreach ( $attempts as $attempt ) {
					if ( $operation->matchesRow( $attempt, $attempt_digest ) ) {
						$existing = $attempt;
						break;
					}
				}
				if ( is_wp_error( $existing ) || ! is_array( $existing ) ) {
					$latest = $attempts[ count( $attempts ) - 1 ] ?? null;
					return is_array( $latest )
						? self::result( $latest, self::ATTENTION_REQUIRED, 'purchase_attempt_in_progress', true )
						: self::operationConflict();
				}
				$replayed = true;
			}
		}

		if ( ! $operation->matchesIdentityRow( $existing ) ) {
			return self::result( $existing, self::ATTENTION_REQUIRED, 'operation_identity_mismatch', $replayed );
		}

		if ( YSHelcimOperationState::REMOTE_CREATED !== (string) $existing['remote_status'] ) {
			return $this->resume( $operation, $existing, $incoming_id, true );
		}

		if ( ! self::isUsableCardToken( $card_token ) ) {
			return self::invalidCardToken();
		}

		$claimed = $this->operations->claimRemoteProcessing( (string) $existing['operation_uuid'] );
		if ( is_wp_error( $claimed ) ) {
			return $claimed;
		}
		if ( true !== $claimed ) {
			$current = $this->operations->findByUuid( (string) $existing['operation_uuid'] );
			if ( null === $current || ! $operation->matchesIdentityRow( $current ) ) {
				return self::operationConflict();
			}

			return $this->resume( $operation, $current, $incoming_id, true );
		}

		$row = $this->operations->findByUuid( (string) $existing['operation_uuid'] );
		if ( null === $row || ! $operation->matchesIdentityRow( $row ) ) {
			return self::result( $existing, self::INDETERMINATE, 'claimed_operation_unreadable', $replayed );
		}

		try {
			$provider_outcome = ( $this->provider_purchase )(
				$identity,
				$card_token,
				(string) $row['idempotency_key'],
				(string) $row['operation_uuid']
			);
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return $this->persistIndeterminate( $row, 'provider_exception', $replayed );
		}

		$approved = self::strictApprovedTransaction( $provider_outcome, $identity );
		if ( null !== $approved ) {
			$provider_id = YSHelcimTransactionId::normalize( $approved['transactionId'] );
			if ( null === $provider_id ) {
				return $this->persistIndeterminate( $row, 'provider_proof_invalid', $replayed );
			}

			$persisted = $this->operations->transitionRemote(
				(string) $row['operation_uuid'],
				YSHelcimOperationState::REMOTE_PROCESSING,
				YSHelcimOperationState::REMOTE_SUCCEEDED,
				array( 'vendor_transaction_id' => $provider_id )
			);
			$current = $this->operations->findByUuid( (string) $row['operation_uuid'] );
			if (
				true !== $persisted ||
				null === $current ||
				YSHelcimOperationState::REMOTE_SUCCEEDED !== (string) $current['remote_status'] ||
				$provider_id !== YSHelcimTransactionId::normalize( $current['vendor_transaction_id'] ?? null )
			) {
				return self::result(
					$current ?? $row,
					self::INDETERMINATE,
					'journal_outcome_unpersisted',
					$replayed
				);
			}

			return $this->completeLocalBinding( $operation, $current, $incoming_id, $replayed );
		}

		if ( self::isStrictDecline( $provider_outcome, $identity ) ) {
			$persisted = $this->operations->transitionRemote(
				(string) $row['operation_uuid'],
				YSHelcimOperationState::REMOTE_PROCESSING,
				YSHelcimOperationState::REMOTE_DECLINED,
				array(
					'error_code'    => 'provider_declined',
					'error_message' => 'The provider definitively declined the purchase.',
				)
			);
			$current = $this->operations->findByUuid( (string) $row['operation_uuid'] );
			if ( true === $persisted && is_array( $current ) && YSHelcimOperationState::REMOTE_DECLINED === $current['remote_status'] ) {
				return self::result( $current, self::DECLINED, 'provider_declined', $replayed );
			}

			return self::result( $current ?? $row, self::INDETERMINATE, 'journal_outcome_unpersisted', $replayed );
		}

		$failure_disposition = self::strictTerminalFailureDisposition( $provider_outcome );
		if ( null !== $failure_disposition ) {
			if ( 'authentication_rejected' === $failure_disposition ) {
				$error_code = 'provider_authentication_rejected';
			} elseif ( 'validation_rejected' === $failure_disposition ) {
				$error_code = 'provider_validation_rejected';
			} else {
				$error_code = 'purchase_never_sent';
			}
			$persisted = $this->operations->transitionRemote(
				(string) $row['operation_uuid'],
				YSHelcimOperationState::REMOTE_PROCESSING,
				YSHelcimOperationState::REMOTE_FAILED,
				array(
					'error_code'    => $error_code,
					'error_message' => 'The purchase was definitively rejected before a charge could be created.',
				)
			);
			$current = $this->operations->findByUuid( (string) $row['operation_uuid'] );
			if ( true === $persisted && is_array( $current ) && YSHelcimOperationState::REMOTE_FAILED === $current['remote_status'] ) {
				return self::result( $current, self::FAILED, $error_code, $replayed );
			}

			return self::result( $current ?? $row, self::INDETERMINATE, 'journal_outcome_unpersisted', $replayed );
		}

		return $this->persistIndeterminate( $row, 'provider_outcome_unproven', $replayed );
	}

	/**
	 * Reconcile an exact provider proof to one explicitly addressed attempt.
	 *
	 * This path never invokes the provider mutation callback and never selects
	 * an attempt by order recency.
	 *
	 * @param array<string, mixed> $transaction Server-loaded FluentCart identity.
	 * @param array<string, mixed> $provider_proof Strict coordinator proof envelope including
	 *                                             `operation_correlation` parsed from provider data.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function reconcileProviderProof(
		array $transaction,
		string $operation_uuid,
		array $provider_proof
	) {
		$operation = YSHelcimPurchaseOperation::fromTransaction( $transaction );
		if ( is_wp_error( $operation ) ) {
			return $operation;
		}

		$operation_uuid = strtolower( trim( $operation_uuid ) );
		if ( 1 !== preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $operation_uuid ) ) {
			return self::result(
				array( 'operation_uuid' => $operation_uuid ),
				self::ATTENTION_REQUIRED,
				'attempt_not_found',
				true
			);
		}

		$row = $this->operations->findByUuid( $operation_uuid );
		if ( null === $row ) {
			return self::result(
				array( 'operation_uuid' => $operation_uuid ),
				self::ATTENTION_REQUIRED,
				'attempt_not_found',
				true
			);
		}
		if ( ! $operation->matchesIdentityRow( $row ) ) {
			return self::result( $row, self::ATTENTION_REQUIRED, 'operation_identity_mismatch', true );
		}
		$correlation = $provider_proof['operation_correlation'] ?? null;
		if ( ! is_string( $correlation ) || ! hash_equals( $operation_uuid, $correlation ) ) {
			return self::result( $row, self::ATTENTION_REQUIRED, 'provider_correlation_mismatch', true );
		}
		unset( $provider_proof['operation_correlation'] );

		$identity = $operation->identity();
		$approved = self::strictApprovedTransaction( $provider_proof, $identity );
		$declined = self::isStrictDecline( $provider_proof, $identity );
		if ( null === $approved && ! $declined ) {
			return self::result( $row, self::ATTENTION_REQUIRED, 'provider_proof_invalid', true );
		}

		$remote_status = (string) $row['remote_status'];
		if ( null !== $approved ) {
			$provider_id = YSHelcimTransactionId::normalize( $approved['transactionId'] ?? null );
			if ( null === $provider_id ) {
				return self::result( $row, self::ATTENTION_REQUIRED, 'provider_proof_invalid', true );
			}

			if ( YSHelcimOperationState::REMOTE_SUCCEEDED === $remote_status ) {
				if ( $provider_id !== YSHelcimTransactionId::normalize( $row['vendor_transaction_id'] ?? null ) ) {
					return self::result( $row, self::ATTENTION_REQUIRED, 'provider_id_mismatch', true );
				}

				return $this->completeLocalBinding( $operation, $row, $provider_id, true );
			}

			if ( ! in_array( $remote_status, array( YSHelcimOperationState::REMOTE_PROCESSING, YSHelcimOperationState::REMOTE_INDETERMINATE ), true ) ) {
				return self::result( $row, self::ATTENTION_REQUIRED, 'attempt_status_conflict', true );
			}

			$persisted = $this->operations->transitionRemote(
				$operation_uuid,
				$remote_status,
				YSHelcimOperationState::REMOTE_SUCCEEDED,
				array( 'vendor_transaction_id' => $provider_id )
			);
			$current = $this->operations->findByUuid( $operation_uuid );
			if (
				true !== $persisted ||
				! is_array( $current ) ||
				YSHelcimOperationState::REMOTE_SUCCEEDED !== (string) $current['remote_status'] ||
				$provider_id !== YSHelcimTransactionId::normalize( $current['vendor_transaction_id'] ?? null )
			) {
				return self::result( $current ?? $row, self::INDETERMINATE, 'journal_outcome_unpersisted', true );
			}

			return $this->completeLocalBinding( $operation, $current, $provider_id, true );
		}

		if ( YSHelcimOperationState::REMOTE_DECLINED === $remote_status ) {
			return self::result( $row, self::DECLINED, 'provider_declined', true );
		}
		if ( ! in_array( $remote_status, array( YSHelcimOperationState::REMOTE_PROCESSING, YSHelcimOperationState::REMOTE_INDETERMINATE ), true ) ) {
			return self::result( $row, self::ATTENTION_REQUIRED, 'attempt_status_conflict', true );
		}

		$persisted = $this->operations->transitionRemote(
			$operation_uuid,
			$remote_status,
			YSHelcimOperationState::REMOTE_DECLINED,
			array(
				'error_code'    => 'provider_declined',
				'error_message' => 'An exact provider proof definitively declined the purchase.',
			)
		);
		$current = $this->operations->findByUuid( $operation_uuid );
		if ( true !== $persisted || ! is_array( $current ) || YSHelcimOperationState::REMOTE_DECLINED !== (string) $current['remote_status'] ) {
			return self::result( $current ?? $row, self::INDETERMINATE, 'journal_outcome_unpersisted', true );
		}

		return self::result( $current, self::DECLINED, 'provider_declined', true );
	}

	/** @return array<string, mixed> */
	private function resume(
		YSHelcimPurchaseOperation $operation,
		array $row,
		?string $incoming_id,
		bool $replayed
	): array {
		$remote_status = (string) ( $row['remote_status'] ?? '' );
		if ( YSHelcimOperationState::REMOTE_SUCCEEDED === $remote_status ) {
			return $this->completeLocalBinding( $operation, $row, $incoming_id, $replayed );
		}

		if ( YSHelcimOperationState::REMOTE_DECLINED === $remote_status ) {
			return self::result( $row, self::DECLINED, 'provider_declined', $replayed );
		}

		if ( YSHelcimOperationState::REMOTE_FAILED === $remote_status ) {
			return self::result( $row, self::FAILED, 'provider_terminal_failure', $replayed );
		}

		if (
			YSHelcimOperationState::REMOTE_PROCESSING === $remote_status ||
			YSHelcimOperationState::REMOTE_INDETERMINATE === $remote_status ||
			YSHelcimOperationState::REMOTE_CREATED === $remote_status
		) {
			return self::result( $row, self::INDETERMINATE, 'provider_outcome_unresolved', $replayed );
		}

		return self::result( $row, self::ATTENTION_REQUIRED, 'operation_terminal_without_payment', $replayed );
	}

	/** @return array<string, mixed> */
	private function completeLocalBinding(
		YSHelcimPurchaseOperation $operation,
		array $row,
		?string $incoming_id,
		bool $replayed
	): array {
		$provider_id = YSHelcimTransactionId::normalize( $row['vendor_transaction_id'] ?? null );
		if ( null === $provider_id ) {
			return self::result( $row, self::ATTENTION_REQUIRED, 'provider_id_missing', $replayed );
		}

		if ( null !== $incoming_id && ! hash_equals( $provider_id, $incoming_id ) ) {
			return self::result( $row, self::ATTENTION_REQUIRED, 'provider_id_mismatch', $replayed );
		}

		$local_status = (string) ( $row['local_status'] ?? '' );
		$inspection = $this->inspectLocalBinding( $operation, $row, $provider_id );
		if ( 'mismatch' === $inspection['status'] ) {
			return self::result( $row, self::ATTENTION_REQUIRED, 'provider_id_mismatch', $replayed );
		}
		if ( 'unknown' === $inspection['status'] ) {
			return self::result( $row, self::ATTENTION_REQUIRED, 'local_inspection_unknown', $replayed );
		}
		if ( 'bound' === $inspection['status'] ) {
			if ( YSHelcimOperationState::LOCAL_APPLIED === $local_status ) {
				return self::result( $row, self::SUCCEEDED, null, $replayed );
			}
			return $this->repairAppliedJournal( $row, $provider_id, $replayed );
		}
		// A partial aggregate has the exact provider ID but is not locally done.
		// It deliberately falls through to the ordinary durable binder claim.
		if ( YSHelcimOperationState::LOCAL_APPLIED === $local_status ) {
			return self::result( $row, self::ATTENTION_REQUIRED, 'local_binding_missing', $replayed );
		}

		if ( YSHelcimOperationState::LOCAL_APPLYING === $local_status ) {
			$cutoff = $this->localClaimCutoff();
			$claimed_at = (string) ( $row['local_claimed_at'] ?? '' );
			if (
				null === $cutoff ||
				1 !== preg_match( '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $claimed_at ) ||
				$claimed_at > $cutoff
			) {
				return self::result( $row, self::ATTENTION_REQUIRED, 'local_binding_in_progress', $replayed );
			}

			$claimed = $this->operations->reclaimStaleLocalApplying( (string) $row['operation_uuid'], $cutoff );
		} elseif ( in_array( $local_status, array( YSHelcimOperationState::LOCAL_PENDING, YSHelcimOperationState::LOCAL_FAILED ), true ) ) {
			$claimed = $this->operations->claimLocalApplying( (string) $row['operation_uuid'], $local_status );
		} else {
			return self::result( $row, self::ATTENTION_REQUIRED, 'local_binding_state_invalid', $replayed );
		}

		if ( true !== $claimed ) {
			$current = $this->operations->findByUuid( (string) $row['operation_uuid'] );
			return self::result( $current ?? $row, self::ATTENTION_REQUIRED, 'local_binding_claim_failed', $replayed );
		}

		try {
			$receipt = ( $this->local_binder )(
				$operation->identity(),
				$provider_id,
				(string) $row['operation_uuid']
			);
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$receipt = null;
		}

		$receipt_id = is_array( $receipt )
			? YSHelcimTransactionId::normalize( $receipt['provider_transaction_id'] ?? null )
			: null;
		if (
			! is_array( $receipt ) ||
			! self::hasExactKeys( $receipt, array( 'bound', 'provider_transaction_id' ) ) ||
			true !== ( $receipt['bound'] ?? null ) ||
			$provider_id !== $receipt_id
		) {
			$error_code = is_array( $receipt ) && null !== $receipt_id ? 'provider_id_mismatch' : 'local_bind_failed';
			$this->operations->transitionLocal(
				(string) $row['operation_uuid'],
				YSHelcimOperationState::LOCAL_APPLYING,
				YSHelcimOperationState::LOCAL_FAILED,
				array(
					'error_code'    => $error_code,
					'error_message' => 'The exact provider transaction could not be bound locally.',
				)
			);
			$current = $this->operations->findByUuid( (string) $row['operation_uuid'] );

			return self::result( $current ?? $row, self::ATTENTION_REQUIRED, $error_code, $replayed );
		}

		$applied = $this->operations->transitionLocal(
			(string) $row['operation_uuid'],
			YSHelcimOperationState::LOCAL_APPLYING,
			YSHelcimOperationState::LOCAL_APPLIED
		);
		$current = $this->operations->findByUuid( (string) $row['operation_uuid'] );
		if ( true !== $applied || ! is_array( $current ) || YSHelcimOperationState::LOCAL_APPLIED !== (string) $current['local_status'] ) {
			return self::result( $current ?? $row, self::ATTENTION_REQUIRED, 'local_outcome_unpersisted', $replayed );
		}

		return self::result( $current, self::SUCCEEDED, null, $replayed );
	}

	/** @return array{status:string,provider_transaction_id:?string} */
	private function inspectLocalBinding(
		YSHelcimPurchaseOperation $operation,
		array $row,
		string $provider_id
	): array {
		try {
			$inspection = ( $this->local_inspector )(
				$operation->identity(),
				$provider_id,
				(string) $row['operation_uuid']
			);
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$inspection = null;
		}

		if (
			! is_array( $inspection ) ||
			! self::hasExactKeys( $inspection, array( 'status', 'provider_transaction_id' ) )
		) {
			return array( 'status' => 'unknown', 'provider_transaction_id' => null );
		}

		$status      = $inspection['status'];
		$observed_id = YSHelcimTransactionId::normalize( $inspection['provider_transaction_id'] ?? null );
		if ( 'unbound' === $status && null === $inspection['provider_transaction_id'] ) {
			return array( 'status' => 'unbound', 'provider_transaction_id' => null );
		}
		if ( 'bound' === $status && $provider_id === $observed_id ) {
			return array( 'status' => 'bound', 'provider_transaction_id' => $observed_id );
		}
		if ( 'partial' === $status && $provider_id === $observed_id ) {
			return array( 'status' => 'partial', 'provider_transaction_id' => $observed_id );
		}
		if ( 'mismatch' === $status && null !== $observed_id && $provider_id !== $observed_id ) {
			return array( 'status' => 'mismatch', 'provider_transaction_id' => $observed_id );
		}

		return array( 'status' => 'unknown', 'provider_transaction_id' => null );
	}

	/** @return array<string, mixed> */
	private function repairAppliedJournal( array $row, string $provider_id, bool $replayed ): array {
		$local_status = (string) ( $row['local_status'] ?? '' );
		if ( in_array( $local_status, array( YSHelcimOperationState::LOCAL_PENDING, YSHelcimOperationState::LOCAL_FAILED ), true ) ) {
			$claimed = $this->operations->claimLocalApplying( (string) $row['operation_uuid'], $local_status );
			if ( true !== $claimed ) {
				$current = $this->operations->findByUuid( (string) $row['operation_uuid'] );
				return self::result( $current ?? $row, self::ATTENTION_REQUIRED, 'local_binding_claim_failed', $replayed );
			}
		} elseif ( YSHelcimOperationState::LOCAL_APPLYING !== $local_status ) {
			return self::result( $row, self::ATTENTION_REQUIRED, 'local_binding_state_invalid', $replayed );
		}

		$applied = $this->operations->transitionLocal(
			(string) $row['operation_uuid'],
			YSHelcimOperationState::LOCAL_APPLYING,
			YSHelcimOperationState::LOCAL_APPLIED
		);
		$current = $this->operations->findByUuid( (string) $row['operation_uuid'] );
		if (
			true !== $applied ||
			! is_array( $current ) ||
			YSHelcimOperationState::LOCAL_APPLIED !== (string) $current['local_status'] ||
			$provider_id !== YSHelcimTransactionId::normalize( $current['vendor_transaction_id'] ?? null )
		) {
			return self::result( $current ?? $row, self::ATTENTION_REQUIRED, 'local_outcome_unpersisted', $replayed );
		}

		return self::result( $current, self::SUCCEEDED, null, $replayed );
	}

	private function localClaimCutoff(): ?string {
		$now = \DateTimeImmutable::createFromFormat(
			'!Y-m-d H:i:s',
			(string) ( $this->clock )(),
			new \DateTimeZone( 'UTC' )
		);
		if ( false === $now ) {
			return null;
		}

		return $now->modify( '-' . self::LOCAL_CLAIM_TTL_SECONDS . ' seconds' )->format( 'Y-m-d H:i:s' );
	}

	/** @return array<string, mixed> */
	private function persistIndeterminate( array $row, string $error_code, bool $replayed ): array {
		$persisted = $this->operations->transitionRemote(
			(string) $row['operation_uuid'],
			YSHelcimOperationState::REMOTE_PROCESSING,
			YSHelcimOperationState::REMOTE_INDETERMINATE,
			array(
				'error_code'    => $error_code,
				'error_message' => 'The provider outcome could not be proven; the charge must not be retried.',
			)
		);
		$current = $this->operations->findByUuid( (string) $row['operation_uuid'] );
		if ( true !== $persisted ) {
			return self::result( $current ?? $row, self::INDETERMINATE, 'journal_outcome_unpersisted', $replayed );
		}

		return self::result( $current ?? $row, self::INDETERMINATE, $error_code, $replayed );
	}

	/** @param mixed $outcome @param array<string, int|string> $identity */
	private static function strictApprovedTransaction( mixed $outcome, array $identity ): ?array {
		if (
			! is_array( $outcome ) ||
			! self::hasExactKeys( $outcome, array( 'outcome', 'transaction' ) ) ||
			'succeeded' !== ( $outcome['outcome'] ?? null ) ||
			! is_array( $outcome['transaction'] ?? null ) ||
			! self::hasExactKeys(
				$outcome['transaction'],
				array( 'status', 'type', 'transactionId', 'amount', 'currency' )
			)
		) {
			return null;
		}

		return null === YSHelcimPurchaseProof::failureReason(
			$outcome['transaction'],
			(int) $identity['amount'],
			(string) $identity['currency']
		)
			? $outcome['transaction']
			: null;
	}

	/** @param mixed $outcome @param array<string, int|string> $identity */
	private static function isStrictDecline( mixed $outcome, array $identity ): bool {
		if (
			! is_array( $outcome ) ||
			! self::hasExactKeys( $outcome, array( 'outcome', 'definitive', 'transaction' ) ) ||
			'declined' !== ( $outcome['outcome'] ?? null ) ||
			true !== ( $outcome['definitive'] ?? null ) ||
			! is_array( $outcome['transaction'] ?? null ) ||
			! self::hasExactKeys( $outcome['transaction'], array( 'status', 'type', 'amount', 'currency' ) ) ||
			'DECLINED' !== ( $outcome['transaction']['status'] ?? null )
		) {
			return false;
		}

		$proof = $outcome['transaction'];
		$proof['status']        = 'APPROVED';
		$proof['transactionId'] = '1';

		return null === YSHelcimPurchaseProof::failureReason(
			$proof,
			(int) $identity['amount'],
			(string) $identity['currency']
		);
	}

	/** @param mixed $outcome */
	private static function strictTerminalFailureDisposition( mixed $outcome ): ?string {
		if (
			! is_array( $outcome ) ||
			! self::hasExactKeys( $outcome, array( 'outcome', 'definitive', 'mutation_disposition' ) ) ||
			'failed' !== ( $outcome['outcome'] ?? null ) ||
			true !== ( $outcome['definitive'] ?? null ) ||
			! in_array(
				$outcome['mutation_disposition'] ?? null,
				array( 'never_sent', 'authentication_rejected', 'validation_rejected' ),
				true
			)
		) {
			return null;
		}

		return (string) $outcome['mutation_disposition'];
	}

	/** @param array<string, mixed> $value @param string[] $keys */
	private static function hasExactKeys( array $value, array $keys ): bool {
		return array() === array_diff( $keys, array_keys( $value ) )
			&& array() === array_diff( array_keys( $value ), $keys );
	}

	/** @return array<string, mixed> */
	private static function result( array $row, string $status, ?string $error_code, bool $replayed ): array {
		return array(
			'status'                  => $status,
			'operation_uuid'          => (string) ( $row['operation_uuid'] ?? '' ),
			'remote_status'           => (string) ( $row['remote_status'] ?? YSHelcimOperationState::REMOTE_PROCESSING ),
			'local_status'            => (string) ( $row['local_status'] ?? YSHelcimOperationState::LOCAL_PENDING ),
			'provider_transaction_id' => YSHelcimTransactionId::normalize( $row['vendor_transaction_id'] ?? null ),
			'error_code'              => $error_code,
			'replayed'                => $replayed,
		);
	}

	private static function isUsableCardToken( string $card_token ): bool {
		return '' !== $card_token
			&& strlen( $card_token ) <= 4096
			&& 1 !== preg_match( '/[\x00-\x1F\x7F]/', $card_token );
	}

	/** Produce a one-way request identity that is never a provider idempotency key. */
	private static function attemptDigest( string $card_token ): string {
		return hash_hmac(
			'sha256',
			$card_token,
			wp_salt( 'auth' ) . '|ys-helcim-purchase-attempt-v1'
		);
	}

	private static function invalidCardToken(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_invalid_card_token',
			__( 'A valid ephemeral Helcim card token is required for a new purchase.', 'ys-helcim-via-fluentcart' )
		);
	}

	private static function invalidProviderId(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_invalid_provider_transaction_id',
			__( 'The incoming provider transaction identifier is invalid.', 'ys-helcim-via-fluentcart' )
		);
	}

	private static function invalidOperationUuid(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_invalid_operation',
			__( 'A persistent purchase operation identifier could not be generated.', 'ys-helcim-via-fluentcart' )
		);
	}

	private static function operationConflict(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_operation_conflict',
			__( 'The purchase operation changed while it was being claimed.', 'ys-helcim-via-fluentcart' )
		);
	}
}
