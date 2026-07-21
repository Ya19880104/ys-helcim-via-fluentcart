<?php
/**
 * Durable completed-webhook receipt storage.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Webhook;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * wpdb-backed completed-webhook receipt repository.
 */
final class YSHelcimWebhookReceiptRepository {

	/** @var object */
	private $database;

	/** @var callable */
	private $clock;

	private string $table;

	private int $purge_limit;

	public function __construct(
		?object $database = null,
		?string $table_name = null,
		?callable $clock = null,
		int $purge_limit = 100
	) {
		$database = self::database( $database );
		if ( $purge_limit < 1 || $purge_limit > 1000 ) {
			throw new \InvalidArgumentException( 'Webhook receipt purge limit must be between 1 and 1000.' );
		}

		$this->database    = $database;
		$this->table       = self::normalizeTableName( $table_name ?? self::tableName( $database ) );
		$this->clock       = $clock ?? static fn (): string => gmdate( 'Y-m-d H:i:s' );
		$this->purge_limit = $purge_limit;
	}

	/** @return bool|\WP_Error */
	public function hasCompleted( string $receipt_key ) {
		if ( ! self::isReceiptKey( $receipt_key ) ) {
			return self::invalidReceipt();
		}

		$now = $this->currentTime();
		if ( is_wp_error( $now ) ) {
			return $now;
		}

		$purged = $this->purgeExpired( $now );
		if ( is_wp_error( $purged ) ) {
			return $purged;
		}

		$stored = $this->readExact( $receipt_key );
		if ( is_wp_error( $stored ) || null === $stored ) {
			return $stored ?? false;
		}

		return $stored['expires_at'] > $now;
	}

	/** @return bool|\WP_Error */
	public function complete( string $receipt_key, string $completed_at, string $expires_at ) {
		if (
			! self::isReceiptKey( $receipt_key ) ||
			! self::isSqlDate( $completed_at ) ||
			! self::isSqlDate( $expires_at ) ||
			$expires_at <= $completed_at
		) {
			return self::invalidReceipt();
		}

		$purged = $this->purgeExpired( $completed_at );
		if ( is_wp_error( $purged ) ) {
			return $purged;
		}

		$stored = $this->readExact( $receipt_key );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		if ( is_array( $stored ) && $stored['expires_at'] > $completed_at ) {
			return true;
		}
		if ( is_array( $stored ) ) {
			$deleted = $this->deleteExpiredExact( $receipt_key, $completed_at );
			if ( is_wp_error( $deleted ) ) {
				return $deleted;
			}
		}

		$this->clearLastError();
		$inserted = $this->database->insert(
			$this->table,
			array(
				'receipt_key'  => $receipt_key,
				'completed_at' => $completed_at,
				'expires_at'   => $expires_at,
			)
		);
		if ( 1 === self::affectedRows( $inserted ) && '' === $this->lastError() ) {
			return true;
		}

		// A concurrent request can win the UNIQUE receipt_key insert. Read it
		// back instead of relying on database-specific duplicate-error text.
		$concurrent = $this->readExact( $receipt_key );
		if ( is_wp_error( $concurrent ) ) {
			return $concurrent;
		}

		return is_array( $concurrent ) && $concurrent['expires_at'] > $completed_at
			? true
			: self::unavailable();
	}

	public static function tableName( ?object $database = null ): string {
		$database = self::database( $database );
		return self::normalizeTableName( (string) ( $database->prefix ?? '' ) . 'ys_helcim_webhook_receipts' );
	}

	public static function createSql( ?object $database = null, ?string $table_name = null ): string {
		$database   = self::database( $database );
		$table_name = self::normalizeTableName( $table_name ?? self::tableName( $database ) );
		$collate    = method_exists( $database, 'get_charset_collate' )
			? trim( (string) $database->get_charset_collate() )
			: '';

		return "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			receipt_key char(64) NOT NULL,
			completed_at datetime NOT NULL,
			expires_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY receipt_key (receipt_key),
			KEY expires_at (expires_at)
		) ENGINE=InnoDB {$collate};";
	}

	/** @return int|\WP_Error */
	private function purgeExpired( string $now ) {
		$sql = $this->database->prepare(
			"DELETE FROM {$this->table}
			WHERE expires_at <= %s
			ORDER BY expires_at ASC, id ASC LIMIT %d",
			$now,
			$this->purge_limit
		);

		$this->clearLastError();
		$result = $this->database->query( $sql );
		$count  = self::affectedRows( $result );
		return null === $count || '' !== $this->lastError() ? self::unavailable() : $count;
	}

	/** @return int|\WP_Error */
	private function deleteExpiredExact( string $receipt_key, string $now ) {
		$sql = $this->database->prepare(
			"DELETE FROM {$this->table}
			WHERE receipt_key = %s AND expires_at <= %s
			LIMIT 1",
			$receipt_key,
			$now
		);

		$this->clearLastError();
		$result = $this->database->query( $sql );
		$count  = self::affectedRows( $result );
		return null === $count || $count > 1 || '' !== $this->lastError() ? self::unavailable() : $count;
	}

	/** @return array{receipt_key:string,completed_at:string,expires_at:string}|null|\WP_Error */
	private function readExact( string $receipt_key ) {
		$query = $this->database->prepare(
			"SELECT receipt_key, completed_at, expires_at
			FROM {$this->table}
			WHERE receipt_key = %s
			LIMIT 1",
			$receipt_key
		);

		$this->clearLastError();
		$row = $this->database->get_row( $query, ARRAY_A );
		if ( '' !== $this->lastError() ) {
			return self::unavailable();
		}
		if ( null === $row ) {
			return null;
		}
		if (
			! is_array( $row ) ||
			! is_string( $row['receipt_key'] ?? null ) ||
			! hash_equals( $receipt_key, $row['receipt_key'] ) ||
			! is_string( $row['completed_at'] ?? null ) ||
			! is_string( $row['expires_at'] ?? null ) ||
			! self::isSqlDate( $row['completed_at'] ) ||
			! self::isSqlDate( $row['expires_at'] ) ||
			$row['expires_at'] <= $row['completed_at']
		) {
			return self::unavailable();
		}

		return array(
			'receipt_key'  => $row['receipt_key'],
			'completed_at' => $row['completed_at'],
			'expires_at'   => $row['expires_at'],
		);
	}

	/** @return string|\WP_Error */
	private function currentTime() {
		try {
			$now = ( $this->clock )();
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::unavailable();
		}

		return is_string( $now ) && self::isSqlDate( $now ) ? $now : self::unavailable();
	}

	private function clearLastError(): void {
		if ( property_exists( $this->database, 'last_error' ) ) {
			$this->database->last_error = '';
		}
	}

	private function lastError(): string {
		return (string) ( $this->database->last_error ?? '' );
	}

	private static function affectedRows( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value >= 0 ? $value : null;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A(?:0|[1-9][0-9]*)\z/', $value ) ) {
			return null;
		}

		$maximum = (string) PHP_INT_MAX;
		if ( strlen( $value ) > strlen( $maximum ) || ( strlen( $value ) === strlen( $maximum ) && strcmp( $value, $maximum ) > 0 ) ) {
			return null;
		}

		return (int) $value;
	}

	private static function isReceiptKey( string $receipt_key ): bool {
		return 1 === preg_match( '/\A[a-f0-9]{64}\z/', $receipt_key );
	}

	private static function isSqlDate( string $value ): bool {
		if ( 1 !== preg_match( '/\A(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})\z/', $value, $matches ) ) {
			return false;
		}

		return checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] ) &&
			(int) $matches[4] <= 23 &&
			(int) $matches[5] <= 59 &&
			(int) $matches[6] <= 59;
	}

	private static function normalizeTableName( string $table_name ): string {
		if ( 1 !== preg_match( '/\A[A-Za-z0-9_]+\z/', $table_name ) ) {
			throw new \InvalidArgumentException( 'Invalid webhook receipt table name.' );
		}

		return $table_name;
	}

	/** @return object */
	private static function database( ?object $database ): object {
		if ( null !== $database ) {
			return $database;
		}

		global $wpdb;
		if ( ! is_object( $wpdb ) ) {
			throw new \RuntimeException( 'WordPress database is unavailable.' );
		}

		return $wpdb;
	}

	private static function invalidReceipt(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_invalid_webhook_receipt',
			__( 'The webhook receipt identity or retention dates are invalid.', 'ys-helcim-via-fluentcart' )
		);
	}

	private static function unavailable(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_webhook_receipt_unavailable',
			__( 'The webhook receipt store is unavailable.', 'ys-helcim-via-fluentcart' )
		);
	}
}
