<?php
/**
 * Resolves a locally recorded refund after its durable effects reach terminal states.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Refund;

use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationRepository;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationState;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOutboxRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps the financial scope locked until stock work is known safe, while
 * allowing terminal notification/stat failures to surface as warnings.
 */
final class YSHelcimRefundFinalizer {

	private const PLAN = array(
		'stock_restore'    => array( YSHelcimOutboxRepository::CLASS_AT_MOST_ONCE, 10 ),
		'customer_recount' => array( YSHelcimOutboxRepository::CLASS_IDEMPOTENT, 20 ),
		'refund_hooks'     => array( YSHelcimOutboxRepository::CLASS_AT_MOST_ONCE, 30 ),
	);

	public function __construct(
		private YSHelcimOperationRepository $operations,
		private YSHelcimOutboxRepository $outbox
	) {
	}

	/** @return array<string,mixed>|\WP_Error */
	public function finalize( string $operation_uuid ) {
		return $this->evaluate( $operation_uuid, true );
	}

	/** Read the exact effect outcome without changing journal or scope state. */
	public function inspect( string $operation_uuid ) {
		return $this->evaluate( $operation_uuid, false );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function evaluate( string $operation_uuid, bool $apply ) {
		$operation = $this->operations->findByUuid( strtolower( trim( $operation_uuid ) ) );
		if ( ! is_array( $operation ) ) {
			return self::operationConflict();
		}

		if (
			YSHelcimOperationState::REMOTE_SUCCEEDED !== (string) ( $operation['remote_status'] ?? '' ) ||
			! in_array(
				(string) ( $operation['local_status'] ?? '' ),
				array( YSHelcimOperationState::LOCAL_RECORDED, YSHelcimOperationState::LOCAL_APPLIED ),
				true
			)
		) {
			return self::operationConflict();
		}

		$rows = $this->outbox->allForOperation( (string) $operation['operation_uuid'] );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$effects = $this->validatedEffects( $rows );
		if ( is_wp_error( $effects ) ) {
			return $effects;
		}

		$statuses = array();
		$warnings = array();
		foreach ( $effects as $type => $effect ) {
			$status              = (string) $effect['status'];
			$statuses[ $type ]   = $status;
			if ( in_array( $status, array( YSHelcimOutboxRepository::STATUS_FAILED, YSHelcimOutboxRepository::STATUS_INDETERMINATE ), true ) ) {
				$warnings[] = $type;
			}
		}

		$replayed = YSHelcimOperationState::LOCAL_APPLIED === (string) $operation['local_status'];
		if ( $replayed ) {
			return $this->result( $warnings ? 'applied_with_warnings' : 'applied', $warnings, $statuses, true );
		}

		if (
			! in_array(
				$statuses['stock_restore'],
				array(
					YSHelcimOutboxRepository::STATUS_PENDING,
					YSHelcimOutboxRepository::STATUS_PROCESSING,
					YSHelcimOutboxRepository::STATUS_COMPLETED,
					YSHelcimOutboxRepository::STATUS_SKIPPED,
				),
				true
			)
		) {
			return $this->result( 'stock_reconciliation_required', array( 'stock_restore' ), $statuses, false );
		}

		foreach ( $statuses as $status ) {
			if ( in_array( $status, array( YSHelcimOutboxRepository::STATUS_PENDING, YSHelcimOutboxRepository::STATUS_PROCESSING ), true ) ) {
				return $this->result( 'waiting', $warnings, $statuses, false );
			}
		}

		if ( ! $apply ) {
			return $this->result( 'ready_to_apply', $warnings, $statuses, false );
		}

		$applied = $this->operations->transitionLocal(
			(string) $operation['operation_uuid'],
			YSHelcimOperationState::LOCAL_RECORDED,
			YSHelcimOperationState::LOCAL_APPLIED
		);
		if ( is_wp_error( $applied ) ) {
			return $applied;
		}
		if ( true !== $applied ) {
			$current = $this->operations->findByUuid( (string) $operation['operation_uuid'] );
			if ( ! is_array( $current ) || YSHelcimOperationState::LOCAL_APPLIED !== (string) ( $current['local_status'] ?? '' ) ) {
				return self::operationConflict();
			}
			$replayed = true;
		}

		return $this->result( $warnings ? 'applied_with_warnings' : 'applied', $warnings, $statuses, $replayed );
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<string,array<string,mixed>>|\WP_Error
	 */
	private function validatedEffects( array $rows ) {
		if ( count( $rows ) !== count( self::PLAN ) ) {
			return self::effectPlanConflict();
		}

		$effects = array();
		foreach ( $rows as $row ) {
			$type = (string) ( $row['effect_type'] ?? '' );
			if ( ! isset( self::PLAN[ $type ] ) || isset( $effects[ $type ] ) ) {
				return self::effectPlanConflict();
			}

			list( $expected_class, $expected_sequence ) = self::PLAN[ $type ];
			$status = (string) ( $row['status'] ?? '' );
			$allowed_statuses = array(
				YSHelcimOutboxRepository::STATUS_PENDING,
				YSHelcimOutboxRepository::STATUS_PROCESSING,
				YSHelcimOutboxRepository::STATUS_COMPLETED,
				YSHelcimOutboxRepository::STATUS_FAILED,
				YSHelcimOutboxRepository::STATUS_INDETERMINATE,
			);
			if ( 'stock_restore' === $type ) {
				$allowed_statuses[] = YSHelcimOutboxRepository::STATUS_SKIPPED;
			}

			if (
				$expected_class !== (string) ( $row['effect_class'] ?? '' ) ||
				$expected_sequence !== (int) ( $row['sequence'] ?? -1 ) ||
				! in_array( $status, $allowed_statuses, true )
			) {
				return self::effectPlanConflict();
			}
			$effects[ $type ] = $row;
		}

		if ( array_keys( self::PLAN ) !== array_keys( $effects ) ) {
			return self::effectPlanConflict();
		}

		return $effects;
	}

	/** @param string[] $warnings @param array<string,string> $statuses @return array<string,mixed> */
	private function result( string $status, array $warnings, array $statuses, bool $replayed ): array {
		return array(
			'status'              => $status,
			'local_status'        => in_array( $status, array( 'applied', 'applied_with_warnings' ), true ) ? 'applied' : 'recorded',
			'notification_status' => match ( $statuses['refund_hooks'] ) {
				YSHelcimOutboxRepository::STATUS_COMPLETED => 'delivered',
				YSHelcimOutboxRepository::STATUS_FAILED,
				YSHelcimOutboxRepository::STATUS_INDETERMINATE => 'attention_required',
				default => 'pending',
			},
			'warnings'            => array_values( $warnings ),
			'effect_statuses'     => $statuses,
			'replayed'            => $replayed,
		);
	}

	private static function operationConflict(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_local_operation_conflict',
			__( 'The saved refund operation is not ready to be finalized.', 'ys-helcim-via-fluentcart' )
		);
	}

	private static function effectPlanConflict(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_effect_plan_conflict',
			__( 'The saved refund effect plan does not match this operation.', 'ys-helcim-via-fluentcart' )
		);
	}
}
