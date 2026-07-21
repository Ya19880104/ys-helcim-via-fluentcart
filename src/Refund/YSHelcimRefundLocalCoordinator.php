<?php
/**
 * Coordinates local refund recording and bounded outbox progress.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Refund;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class YSHelcimRefundLocalCoordinator {

	/** @var callable */
	private $recorder;

	/** @var callable */
	private $worker;

	/** @var callable */
	private $finalizer;

	/** @var callable */
	private $scheduler;

	public function __construct(
		callable $recorder,
		callable $worker,
		callable $finalizer,
		callable $scheduler,
		private int $max_attempts = 4
	) {
		$this->recorder   = $recorder;
		$this->worker     = $worker;
		$this->finalizer  = $finalizer;
		$this->scheduler  = $scheduler;
		$this->max_attempts = max( 1, min( 10, $this->max_attempts ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function record( string $operation_uuid ) {
		try {
			$recorded = ( $this->recorder )( $operation_uuid );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::localUnavailable();
		}
		if ( is_wp_error( $recorded ) ) {
			return $recorded;
		}
		if ( ! is_array( $recorded ) ) {
			return self::localUnavailable();
		}
		if (
			'recorded' === (string) ( $recorded['local_status'] ?? '' ) &&
			true !== ( $recorded['replayed'] ?? null )
		) {
			$recorded['recovery_scheduled'] = $this->schedule( $operation_uuid );
			if ( ! $recorded['recovery_scheduled'] ) {
				return self::recoveryNotScheduled( $recorded );
			}
		}

		for ( $attempt = 0; $attempt < $this->max_attempts; ++$attempt ) {
			$state = $this->finalize( $operation_uuid );
			if ( is_wp_error( $state ) ) {
				return $state;
			}

			$status = (string) $state['status'];
			if ( in_array( $status, array( 'applied', 'applied_with_warnings', 'stock_reconciliation_required' ), true ) ) {
				return $this->mergeState( $recorded, $state );
			}

			try {
				$worked = ( $this->worker )( $operation_uuid );
			} catch ( \Throwable $exception ) {
				unset( $exception );
				$worked = self::effectUnavailable();
			}
			if ( is_wp_error( $worked ) ) {
				$post_error_state = $this->finalize( $operation_uuid );
				if ( ! is_wp_error( $post_error_state ) ) {
					$recorded = $this->mergeState( $recorded, $post_error_state );
					if ( in_array( (string) $post_error_state['status'], array( 'applied', 'applied_with_warnings', 'stock_reconciliation_required' ), true ) ) {
						$recorded['effect_error_code'] = self::safeErrorCode( $worked->get_error_code() );
						return $recorded;
					}
				}
				$recorded['local_status']          = 'recorded';
				$recorded['notification_status']   = 'attention_required';
				$recorded['effect_error_code']     = self::safeErrorCode( $worked->get_error_code() );
				$recorded['warnings']              = array( 'effect_processing' );
				return $recorded;
			}
			if ( null === $worked ) {
				break;
			}
		}

		$state = $this->finalize( $operation_uuid );
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		if ( in_array( (string) $state['status'], array( 'applied', 'applied_with_warnings', 'stock_reconciliation_required' ), true ) ) {
			return $this->mergeState( $recorded, $state );
		}

		$recorded = $this->mergeState( $recorded, $state );
		return $recorded;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function finalize( string $operation_uuid ) {
		try {
			$state = ( $this->finalizer )( $operation_uuid );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::effectUnavailable();
		}

		if (
			! is_array( $state ) ||
			! in_array( (string) ( $state['status'] ?? '' ), array( 'waiting', 'applied', 'applied_with_warnings', 'stock_reconciliation_required' ), true ) ||
			! in_array( (string) ( $state['local_status'] ?? '' ), array( 'recorded', 'applied' ), true ) ||
			! in_array( (string) ( $state['notification_status'] ?? '' ), array( 'pending', 'delivered', 'attention_required' ), true ) ||
			! is_array( $state['warnings'] ?? null )
		) {
			return is_wp_error( $state ) ? $state : self::effectUnavailable();
		}

		return $state;
	}

	/** @param array<string,mixed> $recorded @param array<string,mixed> $state @return array<string,mixed> */
	private function mergeState( array $recorded, array $state ): array {
		$recorded['local_status']        = (string) $state['local_status'];
		$recorded['notification_status'] = (string) $state['notification_status'];
		$recorded['warnings']            = array_values( $state['warnings'] );
		$recorded['effect_status']       = (string) $state['status'];
		if ( 'stock_reconciliation_required' === $state['status'] ) {
			$recorded['manual_reconciliation_required'] = true;
		}

		return $recorded;
	}

	private function schedule( string $operation_uuid ): bool {
		try {
			return true === ( $this->scheduler )( $operation_uuid );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return false;
		}
	}

	/** @param array<string,mixed> $recorded @return array<string,mixed> */
	private static function recoveryNotScheduled( array $recorded ): array {
		$recorded['local_status']        = 'recorded';
		$recorded['notification_status'] = 'attention_required';
		$recorded['warnings']            = array( 'recovery_scheduling' );
		$recorded['effect_status']       = 'recovery_not_scheduled';
		$recorded['recovery_scheduled']  = false;
		$recorded['recovery_error_code'] = 'ys_helcim_recovery_schedule_failed';
		return $recorded;
	}

	private static function safeErrorCode( mixed $code ): string {
		$code = is_string( $code ) ? strtolower( $code ) : '';
		$code = preg_replace( '/[^a-z0-9_-]/', '', $code ) ?? '';
		return '' === $code ? 'ys_helcim_effect_unavailable' : substr( $code, 0, 100 );
	}

	private static function localUnavailable(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_local_recording_failed',
			__( 'The refund could not be recorded locally.', 'ys-helcim-via-fluentcart' )
		);
	}

	private static function effectUnavailable(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_effect_processing_failed',
			__( 'The recorded refund effects could not be processed safely.', 'ys-helcim-via-fluentcart' )
		);
	}
}
