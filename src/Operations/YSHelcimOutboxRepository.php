<?php
/**
 * Durable effect outbox for post-refund work.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Operations;

use YangSheep\Helcim\FluentCart\Support\YSHelcimSanitizer;
use YangSheep\Helcim\FluentCart\Support\YSHelcimLogger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Claims effects in sequence and distinguishes retry-safe work from external
 * hooks that must never be replayed automatically after an ambiguous crash.
 */
final class YSHelcimOutboxRepository {
	private const RECOVERY_CURSOR_OPTION = 'ys_helcim_outbox_recovery_cursor_v1';

	public const CLASS_TRANSACTIONAL = 'transactional';
	public const CLASS_IDEMPOTENT     = 'idempotent';
	public const CLASS_AT_MOST_ONCE   = 'at_most_once';

	public const STATUS_PENDING       = 'pending';
	public const STATUS_PROCESSING    = 'processing';
	public const STATUS_COMPLETED     = 'completed';
	public const STATUS_SKIPPED       = 'skipped';
	public const STATUS_FAILED        = 'failed';
	public const STATUS_INDETERMINATE = 'indeterminate';

	/** @var object */
	private $database;

	/** @var callable */
	private $clock;

	/** @var callable */
	private $uuid_factory;

	/** @var callable */
	private $recovery_cursor_reader;

	/** @var callable */
	private $recovery_cursor_writer;

	private string $table;

	private string $operation_table;

	public function __construct(
		?object $database = null,
		?callable $clock = null,
		?callable $uuid_factory = null,
		?callable $recovery_cursor_reader = null,
		?callable $recovery_cursor_writer = null
	) {
		if ( null === $database ) {
			global $wpdb;
			$database = $wpdb;
		}
		$this->database     = $database;
		$this->table        = YSHelcimOperationSchema::outboxTableName( $database );
		$this->operation_table = YSHelcimOperationSchema::tableName( $database );
		$this->clock        = $clock ?? static fn (): string => gmdate( 'Y-m-d H:i:s' );
		$this->uuid_factory = $uuid_factory ?? static fn (): string => wp_generate_uuid4();
		$this->recovery_cursor_reader = $recovery_cursor_reader ?? static fn (): mixed => get_option( self::RECOVERY_CURSOR_OPTION, 0 );
		$this->recovery_cursor_writer = $recovery_cursor_writer ?? static function ( int $cursor ): void {
			update_option( self::RECOVERY_CURSOR_OPTION, $cursor, false );
		};
	}

	/** @param array<string,mixed> $payload @return array|\WP_Error */
	public function enqueue(
		string $operation_uuid,
		string $effect_type,
		string $effect_class,
		int $sequence,
		array $payload,
		string $status = self::STATUS_PENDING
	) {
		$operation_uuid = strtolower( trim( $operation_uuid ) );
		$effect_type    = strtolower( trim( $effect_type ) );
		if (
			1 !== preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $operation_uuid ) ||
			1 !== preg_match( '/\A[a-z][a-z0-9_-]{0,63}\z/', $effect_type ) ||
			! in_array( $effect_class, self::effectClasses(), true ) ||
			$sequence < 0 || $sequence > 65535 ||
			! in_array( $status, array( self::STATUS_PENDING, self::STATUS_SKIPPED ), true )
		) {
			return self::invalidEffect();
		}

		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) || strlen( $json ) > 65535 ) {
			return self::invalidEffect();
		}

		$now = ( $this->clock )();
		$row = array(
			'operation_uuid'    => $operation_uuid,
			'effect_type'       => $effect_type,
			'effect_class'      => $effect_class,
			'sequence'          => $sequence,
			'payload'           => $json,
			'payload_hash'      => hash( 'sha256', $json ),
			'status'            => $status,
			'attempt_count'     => 0,
			'claim_token'       => null,
			'available_at'      => $now,
			'claimed_at'        => null,
			'completed_at'      => self::STATUS_SKIPPED === $status ? $now : null,
			'result_hash'       => null,
			'last_error_code'   => null,
			'last_error_message' => null,
			'created_at'        => $now,
			'updated_at'        => $now,
		);

		$inserted = $this->database->insert( $this->table, $row );
		if ( false === $inserted ) {
			$existing = $this->find( $operation_uuid, $effect_type );
			$existing_initial_status = is_array( $existing ) && self::STATUS_SKIPPED === (string) ( $existing['status'] ?? '' )
				? self::STATUS_SKIPPED
				: self::STATUS_PENDING;
			if (
				null !== $existing &&
				(string) ( $existing['effect_class'] ?? '' ) === $effect_class &&
				(int) ( $existing['sequence'] ?? -1 ) === $sequence &&
				$existing_initial_status === $status &&
				hash_equals( (string) $existing['payload_hash'], $row['payload_hash'] )
			) {
				return $existing;
			}
			return self::effectConflict();
		}

		return $this->find( $operation_uuid, $effect_type ) ?? self::outboxUnavailable();
	}

	public function find( string $operation_uuid, string $effect_type ): ?array {
		$row = $this->database->get_row(
			$this->database->prepare(
				"SELECT * FROM {$this->table} WHERE operation_uuid = %s AND effect_type = %s LIMIT 1",
				strtolower( $operation_uuid ),
				strtolower( $effect_type )
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/** @return array<int,array<string,mixed>>|\WP_Error */
	public function allForOperation( string $operation_uuid ) {
		$operation_uuid = strtolower( trim( $operation_uuid ) );
		if ( 1 !== preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $operation_uuid ) ) {
			return self::invalidEffect();
		}

		$rows = $this->database->get_results(
			$this->database->prepare(
				"SELECT * FROM {$this->table} WHERE operation_uuid = %s ORDER BY sequence ASC, id ASC",
				$operation_uuid
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : self::outboxUnavailable();
	}

	/**
	 * Return fair, actionable refund operations.
	 *
	 * Besides due effects this includes the crash window where every effect is
	 * terminal but the operation is still locally recorded. Operations already
	 * applied, or blocked by an indeterminate stock effect, are deliberately
	 * excluded so they cannot monopolize the bounded recurring sweep.
	 *
	 * @return string[]|\WP_Error
	 */
	public function actionableOperationUuids( int $limit = 100 ) {
		$limit = max( 1, min( 500, $limit ) );
		$this->clearDatabaseError();
		try {
			$rows = $this->database->get_results(
				$this->database->prepare(
					"/* ys_helcim_actionable_operations */
					SELECT effects.operation_uuid
					FROM {$this->table} AS effects
					INNER JOIN {$this->operation_table} AS operations
						ON operations.operation_uuid = effects.operation_uuid
					WHERE operations.operation_type IN ('refund', 'reverse')
						AND operations.remote_status = 'succeeded'
						AND operations.local_status = 'recorded'
						AND NOT EXISTS (
							SELECT 1 FROM {$this->table} AS blocked
							WHERE blocked.operation_uuid = effects.operation_uuid
								AND blocked.effect_type = 'stock_restore'
								AND blocked.status IN ('failed', 'indeterminate')
						)
					GROUP BY effects.operation_uuid
					HAVING COUNT(*) = 3
						AND SUM(CASE WHEN effects.effect_type = 'stock_restore' AND effects.effect_class = 'at_most_once' AND effects.sequence = 10 THEN 1 ELSE 0 END) = 1
						AND SUM(CASE WHEN effects.effect_type = 'customer_recount' AND effects.effect_class = 'idempotent' AND effects.sequence = 20 THEN 1 ELSE 0 END) = 1
						AND SUM(CASE WHEN effects.effect_type = 'refund_hooks' AND effects.effect_class = 'at_most_once' AND effects.sequence = 30 THEN 1 ELSE 0 END) = 1
						AND (
							SUM(CASE WHEN effects.status = 'pending' AND effects.available_at <= %s THEN 1 ELSE 0 END) > 0
							OR SUM(CASE WHEN effects.status IN ('pending', 'processing') THEN 1 ELSE 0 END) = 0
						)
					ORDER BY
						CASE WHEN SUM(CASE WHEN effects.status IN ('pending', 'processing') THEN 1 ELSE 0 END) = 0 THEN 0 ELSE 1 END ASC,
						MIN(CASE WHEN effects.status = 'pending' THEN effects.available_at ELSE '9999-12-31 23:59:59' END) ASC,
						MIN(effects.id) ASC
					LIMIT %d",
					( $this->clock )(),
					$limit
				),
				ARRAY_A
			);
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::outboxUnavailable();
		}
		if ( $this->hasDatabaseError() || ! is_array( $rows ) || count( $rows ) > $limit ) {
			return self::outboxUnavailable();
		}

		$operation_uuids = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || array( 'operation_uuid' ) !== array_keys( $row ) ) {
				return self::outboxUnavailable();
			}
			$operation_uuid = $row['operation_uuid'];
			if ( ! self::isUuid( $operation_uuid ) || isset( $operation_uuids[ $operation_uuid ] ) ) {
				return self::outboxUnavailable();
			}
			$operation_uuids[ $operation_uuid ] = true;
		}

		return array_keys( $operation_uuids );
	}

	/**
	 * Recover stale claims in isolated row transactions.
	 *
	 * A malformed or permanently failing row must not roll back healthy rows or
	 * monopolize every later sweep. Each candidate is re-read FOR UPDATE before
	 * mutation. Corrupt rows are quarantined as indeterminate and never replayed.
	 *
	 * @return string[]|\WP_Error
	 */
	public function recoverStaleOperationUuids( string $claimed_before, int $limit = 100 ) {
		$limit      = max( 1, min( 500, $limit ) );
		$scan_limit = min( 500, max( 10, $limit * 10 ) );
		if ( 1 !== preg_match( '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $claimed_before ) ) {
			return self::outboxUnavailable();
		}

		$cursor = $this->readRecoveryCursor();
		$rows   = $this->staleCandidateRows( $claimed_before, $cursor, $scan_limit );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		if ( empty( $rows ) && $cursor > 0 ) {
			$this->writeRecoveryCursor( 0 );
			$cursor = 0;
			$rows   = $this->staleCandidateRows( $claimed_before, 0, $scan_limit );
			if ( is_wp_error( $rows ) ) {
				return $rows;
			}
		}

		$seen_ids        = array();
		$operation_uuids = array();
		$failed_rows     = 0;
		$scanned_rows    = 0;
		$last_scanned_id = $cursor;
		foreach ( $rows as $candidate ) {
			if ( count( $operation_uuids ) >= $limit ) {
				break;
			}
			$id = is_array( $candidate ) ? self::positiveInteger( $candidate['id'] ?? null ) : null;
			if ( null === $id || $id <= $last_scanned_id || isset( $seen_ids[ $id ] ) ) {
				return self::outboxUnavailable();
			}
			$seen_ids[ $id ] = true;
			$last_scanned_id = $id;
			++$scanned_rows;

			if ( false === $this->database->query( 'START TRANSACTION' ) ) {
				++$failed_rows;
				continue;
			}
			$this->clearDatabaseError();
			try {
				$row = $this->database->get_row(
					$this->database->prepare(
						"SELECT * FROM {$this->table}
						WHERE id = %d AND status = %s AND claimed_at <= %s
						LIMIT 1 FOR UPDATE",
						$id,
						self::STATUS_PROCESSING,
						$claimed_before
					),
					ARRAY_A
				);
			} catch ( \Throwable $exception ) {
				unset( $exception );
				$this->database->query( 'ROLLBACK' );
				++$failed_rows;
				continue;
			}
			if ( $this->hasDatabaseError() ) {
				$this->database->query( 'ROLLBACK' );
				++$failed_rows;
				continue;
			}
			if ( ! is_array( $row ) ) {
				$this->database->query( 'COMMIT' );
				continue;
			}

			$id      = self::positiveInteger( $row['id'] ?? null );
			$corrupt =
				null === $id ||
				! self::isUuid( $row['operation_uuid'] ?? null ) ||
				self::STATUS_PROCESSING !== ( $row['status'] ?? null ) ||
				! in_array( $row['effect_class'] ?? null, self::effectClasses(), true ) ||
				! self::isUuid( $row['claim_token'] ?? null ) ||
				! is_string( $row['claimed_at'] ?? null ) ||
				1 !== preg_match( '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $row['claimed_at'] ) ||
				$row['claimed_at'] > $claimed_before;
			if ( $corrupt ) {
				$updated = $this->database->update(
					$this->table,
					array(
						'status'             => self::STATUS_INDETERMINATE,
						'claim_token'        => null,
						'last_error_code'    => 'ys_helcim_corrupt_stale_claim',
						'last_error_message' => __( 'A corrupt abandoned effect was quarantined for manual review.', 'ys-helcim-via-fluentcart' ),
						'updated_at'         => ( $this->clock )(),
					),
					array( 'id' => (int) ( $row['id'] ?? 0 ), 'status' => self::STATUS_PROCESSING )
				);
				if ( 1 !== $updated || false === $this->database->query( 'COMMIT' ) ) {
					$this->database->query( 'ROLLBACK' );
					++$failed_rows;
				}
				continue;
			}

			$next = self::CLASS_AT_MOST_ONCE === (string) $row['effect_class']
				? self::STATUS_INDETERMINATE
				: self::STATUS_PENDING;
			$now  = ( $this->clock )();
			$updated = $this->database->update(
				$this->table,
				array(
					'status'      => $next,
					'claim_token' => null,
					'claimed_at'  => self::STATUS_PENDING === $next ? null : $row['claimed_at'],
					'updated_at'  => $now,
				),
				array( 'id' => (int) $row['id'], 'status' => self::STATUS_PROCESSING, 'claim_token' => $row['claim_token'] )
			);
			if ( 1 !== $updated ) {
				$this->database->query( 'ROLLBACK' );
				++$failed_rows;
				continue;
			}
			if ( false === $this->database->query( 'COMMIT' ) ) {
				$this->database->query( 'ROLLBACK' );
				++$failed_rows;
				continue;
			}
			$operation_uuids[ (string) $row['operation_uuid'] ] = true;
		}
		$has_unscanned_rows = $scanned_rows < count( $rows );
		$next_cursor = $has_unscanned_rows || count( $rows ) === $scan_limit
			? $last_scanned_id
			: 0;
		$this->writeRecoveryCursor( $next_cursor );
		if ( $failed_rows > 0 ) {
			YSHelcimLogger::error(
				'Refund outbox stale-claim recovery encountered isolated row failures.',
				array(
					'failed_rows' => $failed_rows,
					'scanned_rows' => count( $rows ),
					'recovered_operations' => count( $operation_uuids ),
				)
			);
		}

		return array_keys( $operation_uuids );
	}

	/** @return array<int,array{id:mixed}>|\WP_Error */
	private function staleCandidateRows( string $claimed_before, int $after_id, int $limit ) {
		$this->clearDatabaseError();
		try {
			$rows = $this->database->get_results(
				$this->database->prepare(
					"SELECT id FROM {$this->table}
					WHERE status = %s AND claimed_at <= %s AND id > %d
					ORDER BY id ASC LIMIT %d",
					self::STATUS_PROCESSING,
					$claimed_before,
					$after_id,
					$limit
				),
				ARRAY_A
			);
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::outboxUnavailable();
		}

		return $this->hasDatabaseError() || ! is_array( $rows ) || count( $rows ) > $limit
			? self::outboxUnavailable()
			: $rows;
	}

	private function readRecoveryCursor(): int {
		try {
			$value = ( $this->recovery_cursor_reader )();
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return 0;
		}
		if ( 0 === $value || '0' === $value ) {
			return 0;
		}

		return self::positiveInteger( $value ) ?? 0;
	}

	private function writeRecoveryCursor( int $cursor ): void {
		try {
			( $this->recovery_cursor_writer )( max( 0, $cursor ) );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			YSHelcimLogger::error( 'Refund outbox recovery cursor could not be persisted.' );
		}
	}

	/** @return array|\WP_Error|null */
	public function claimNext( string $operation_uuid ) {
		if ( false === $this->database->query( 'START TRANSACTION' ) ) {
			return self::outboxUnavailable();
		}

		$this->clearDatabaseError();
		$row = $this->database->get_row(
			$this->database->prepare(
				"SELECT * FROM {$this->table}
				WHERE operation_uuid = %s
				AND status IN ('pending', 'processing')
				ORDER BY sequence ASC, id ASC LIMIT 1 FOR UPDATE",
				strtolower( $operation_uuid )
			),
			ARRAY_A
		);
		if ( $this->hasDatabaseError() ) {
			$this->database->query( 'ROLLBACK' );
			return self::outboxUnavailable();
		}
		if ( ! is_array( $row ) || self::STATUS_PENDING !== (string) $row['status'] || (string) $row['available_at'] > ( $this->clock )() ) {
			return false === $this->database->query( 'COMMIT' ) ? self::outboxUnavailable() : null;
		}

		$claim_token = strtolower( trim( (string) ( $this->uuid_factory )() ) );
		if ( 1 !== preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $claim_token ) ) {
			$this->database->query( 'ROLLBACK' );
			return self::invalidEffect();
		}

		$now     = ( $this->clock )();
		$updated = $this->database->update(
			$this->table,
			array(
				'status'        => self::STATUS_PROCESSING,
				'claim_token'   => $claim_token,
				'claimed_at'    => $now,
				'attempt_count' => (int) $row['attempt_count'] + 1,
				'updated_at'    => $now,
			),
			array( 'id' => (int) $row['id'], 'status' => self::STATUS_PENDING )
		);
		if ( 1 !== $updated || false === $this->database->query( 'COMMIT' ) ) {
			$this->database->query( 'ROLLBACK' );
			return false === $updated ? self::outboxUnavailable() : null;
		}

		return array_merge(
			$row,
			array(
				'status'        => self::STATUS_PROCESSING,
				'claim_token'   => $claim_token,
				'claimed_at'    => $now,
				'attempt_count' => (int) $row['attempt_count'] + 1,
			)
		);
	}

	public function complete( int $id, string $claim_token, mixed $result = null ) {
		$encoded = wp_json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$now     = ( $this->clock )();
		$updated = $this->database->update(
			$this->table,
			array(
				'status'             => self::STATUS_COMPLETED,
				'claim_token'        => null,
				'completed_at'       => $now,
				'result_hash'        => hash( 'sha256', is_string( $encoded ) ? $encoded : 'null' ),
				'last_error_code'    => null,
				'last_error_message' => null,
				'updated_at'         => $now,
			),
			array( 'id' => $id, 'status' => self::STATUS_PROCESSING, 'claim_token' => $claim_token )
		);
		return false === $updated ? self::outboxUnavailable() : 1 === $updated;
	}

	public function fail( int $id, string $claim_token, string $code, string $message, bool $retryable = false ) {
		$current = $this->findByClaim( $id, $claim_token );
		if ( null === $current ) {
			return false;
		}
		$next = self::CLASS_AT_MOST_ONCE === (string) $current['effect_class']
			? self::STATUS_INDETERMINATE
			: ( $retryable ? self::STATUS_PENDING : self::STATUS_FAILED );
		$now = ( $this->clock )();

		$updated = $this->database->update(
			$this->table,
			array(
				'status'             => $next,
				'claim_token'        => null,
				'claimed_at'         => self::STATUS_PENDING === $next ? null : $current['claimed_at'],
				'available_at'       => $now,
				'last_error_code'    => substr( sanitize_key( $code ), 0, 100 ),
				'last_error_message' => YSHelcimSanitizer::errorText( $message ),
				'updated_at'         => $now,
			),
			array( 'id' => $id, 'status' => self::STATUS_PROCESSING, 'claim_token' => $claim_token )
		);
		return false === $updated ? self::outboxUnavailable() : 1 === $updated;
	}

	private function findByClaim( int $id, string $claim_token ): ?array {
		$row = $this->database->get_row(
			$this->database->prepare(
				"SELECT * FROM {$this->table} WHERE id = %d AND status = %s AND claim_token = %s LIMIT 1",
				$id,
				self::STATUS_PROCESSING,
				$claim_token
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	private function clearDatabaseError(): void {
		if ( property_exists( $this->database, 'last_error' ) ) {
			$this->database->last_error = '';
		}
	}

	private function hasDatabaseError(): bool {
		return property_exists( $this->database, 'last_error' ) && '' !== (string) $this->database->last_error;
	}

	private static function isUuid( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match(
			'/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
			$value
		);
	}

	private static function positiveInteger( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A[1-9][0-9]*\z/', $value ) ) {
			return null;
		}
		$maximum = (string) PHP_INT_MAX;
		if ( strlen( $value ) > strlen( $maximum ) || ( strlen( $value ) === strlen( $maximum ) && strcmp( $value, $maximum ) > 0 ) ) {
			return null;
		}
		return (int) $value;
	}

	/** @return string[] */
	private static function effectClasses(): array {
		return array( self::CLASS_TRANSACTIONAL, self::CLASS_IDEMPOTENT, self::CLASS_AT_MOST_ONCE );
	}

	private static function invalidEffect(): \WP_Error {
		return new \WP_Error( 'ys_helcim_invalid_effect', __( 'The refund effect is invalid.', 'ys-helcim-via-fluentcart' ) );
	}

	private static function outboxUnavailable(): \WP_Error {
		return new \WP_Error( 'ys_helcim_outbox_unavailable', __( 'The refund effect journal is unavailable.', 'ys-helcim-via-fluentcart' ) );
	}

	private static function effectConflict(): \WP_Error {
		return new \WP_Error( 'ys_helcim_effect_conflict', __( 'The saved refund effect does not match this operation.', 'ys-helcim-via-fluentcart' ) );
	}
}
