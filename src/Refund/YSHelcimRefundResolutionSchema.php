<?php
/**
 * Independent schema for refund-resolution challenges and immutable audits.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Refund;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class YSHelcimRefundResolutionSchema {

	public const VERSION = '1';
	public const OPTION_NAME = 'ys_helcim_refund_resolution_schema_version';

	/** @param object|null $database */
	public static function challengeTableName( ?object $database = null ): string {
		$database = self::database( $database );
		return $database->prefix . 'ys_helcim_resolution_challenges';
	}

	/** @param object|null $database */
	public static function auditTableName( ?object $database = null ): string {
		$database = self::database( $database );
		return $database->prefix . 'ys_helcim_refund_resolutions';
	}

	/** @param object|null $database */
	public static function challengeCreateSql( ?object $database = null ): string {
		$database = self::database( $database );
		$table    = self::challengeTableName( $database );
		$collate  = $database->get_charset_collate();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			challenge_hash char(64) NOT NULL,
			operation_uuid char(36) NOT NULL,
			gateway varchar(50) NOT NULL,
			payment_mode varchar(10) NOT NULL,
			candidate_transaction_id varchar(64) NOT NULL,
			source_transaction_id varchar(64) NOT NULL,
			action varchar(32) NOT NULL,
			proof_digest char(64) NOT NULL,
			state_updated_at datetime NOT NULL,
			actor_user_id bigint(20) unsigned NOT NULL,
			phrase_hash char(64) NOT NULL,
			parent_attestation_required tinyint(1) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			expires_at datetime NOT NULL,
			used_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY challenge_hash (challenge_hash),
			KEY operation_uuid (operation_uuid),
			KEY candidate_transaction_id (candidate_transaction_id),
			KEY expires_at (expires_at)
		) ENGINE=InnoDB {$collate};";
	}

	/** @param object|null $database */
	public static function auditCreateSql( ?object $database = null ): string {
		$database = self::database( $database );
		$table    = self::auditTableName( $database );
		$collate  = $database->get_charset_collate();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			operation_uuid char(36) NOT NULL,
			challenge_hash char(64) NOT NULL,
			gateway varchar(50) NOT NULL,
			payment_mode varchar(10) NOT NULL,
			operation_type varchar(20) NOT NULL,
			candidate_transaction_id varchar(64) NOT NULL,
			source_transaction_id varchar(64) NOT NULL,
			action varchar(32) NOT NULL,
			proof_digest char(64) NOT NULL,
			state_updated_at datetime NOT NULL,
			actor_user_id bigint(20) unsigned NOT NULL,
			parent_attested tinyint(1) unsigned NOT NULL DEFAULT 0,
			resolved_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY operation_uuid (operation_uuid),
			UNIQUE KEY challenge_hash (challenge_hash),
			UNIQUE KEY candidate_transaction_id (candidate_transaction_id),
			KEY source_transaction_id (source_transaction_id)
		) ENGINE=InnoDB {$collate};";
	}

	/**
	 * Install and verify both concurrency-critical InnoDB tables.
	 *
	 * @param object|null   $database wpdb-compatible database object.
	 * @param callable|null $migrator Optional dbDelta-compatible callable.
	 */
	public static function install( ?object $database = null, ?callable $migrator = null ): bool {
		$database = self::database( $database );
		if ( property_exists( $database, 'last_error' ) ) {
			$database->last_error = '';
		}

		if ( null === $migrator ) {
			if ( ! function_exists( 'dbDelta' ) ) {
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			}
			$migrator = static fn ( string $sql ) => dbDelta( $sql );
		}

		try {
			$migrator( self::challengeCreateSql( $database ) );
			$migrator( self::auditCreateSql( $database ) );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return false;
		}

		if (
			'' !== (string) ( $database->last_error ?? '' ) ||
			! self::hasRequiredSchema( $database )
		) {
			return false;
		}

		update_option( self::OPTION_NAME, self::VERSION, false );
		return true;
	}

	/** @param object|null $database @param callable|null $migrator */
	public static function maybeUpgrade( ?object $database = null, ?callable $migrator = null ): bool {
		if ( self::VERSION !== (string) get_option( self::OPTION_NAME, '' ) ) {
			return self::install( $database, $migrator );
		}
		return true;
	}

	/** Run an explicit physical-schema health check without performing a migration. */
	public static function verifyHealth( ?object $database = null ): bool {
		return self::hasRequiredSchema( self::database( $database ) );
	}

	/** WordPress activation-hook adapter. */
	public static function activate( bool $network_wide = false ): void {
		unset( $network_wide );
		self::install();
	}

	private static function hasRequiredSchema( object $database ): bool {
		return self::hasRequiredTable(
			$database,
			self::challengeTableName( $database ),
			array(
				'challenge_hash' => array( 'challenge_hash' ),
			)
		) && self::hasRequiredTable(
			$database,
			self::auditTableName( $database ),
			array(
				'operation_uuid'           => array( 'operation_uuid' ),
				'challenge_hash'           => array( 'challenge_hash' ),
				'candidate_transaction_id' => array( 'candidate_transaction_id' ),
			)
		);
	}

	/** @param array<string,string[]> $required */
	private static function hasRequiredTable( object $database, string $table, array $required ): bool {
		$like = method_exists( $database, 'esc_like' ) ? $database->esc_like( $table ) : $table;
		$found = $database->get_var( $database->prepare( 'SHOW TABLES LIKE %s', $like ) );
		if ( $table !== $found ) {
			return false;
		}

		$engine = $database->get_var(
			$database->prepare(
				'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				$table
			)
		);
		if ( 0 !== strcasecmp( 'InnoDB', (string) $engine ) ) {
			return false;
		}

		$rows = $database->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A );
		$unique  = array();
		$invalid = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if (
				isset( $row['Key_name'], $row['Column_name'], $row['Non_unique'], $row['Seq_in_index'] ) &&
				'0' === (string) $row['Non_unique']
			) {
				$name = (string) $row['Key_name'];
				$sequence = (int) $row['Seq_in_index'];
				if (
					$sequence <= 0 ||
					( null !== ( $row['Sub_part'] ?? null ) && '' !== (string) $row['Sub_part'] ) ||
					isset( $unique[ $name ][ $sequence ] )
				) {
					$invalid[ $name ] = true;
					continue;
				}
				$unique[ $name ][ $sequence ] = (string) $row['Column_name'];
			}
		}
		foreach ( $required as $name => $columns ) {
			$actual = $unique[ $name ] ?? array();
			ksort( $actual, SORT_NUMERIC );
			if ( isset( $invalid[ $name ] ) || $columns !== array_values( $actual ) ) {
				return false;
			}
		}
		return true;
	}

	/** @param object|null $database */
	private static function database( ?object $database ): object {
		if ( null !== $database ) {
			return $database;
		}
		global $wpdb;
		return $wpdb;
	}
}
