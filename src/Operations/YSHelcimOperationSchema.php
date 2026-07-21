<?php
/**
 * Versioned operation-journal schema.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Operations;

use YangSheep\Helcim\FluentCart\Support\YSHelcimLogger;
use YangSheep\Helcim\FluentCart\Webhook\YSHelcimWebhookReceiptRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Installs the additive Helcim operation table.
 */
final class YSHelcimOperationSchema {

	public const VERSION = '8';

	public const OPTION_NAME = 'ys_helcim_operation_schema_version';

	/** @param object|null $database wpdb-compatible database object. */
	public static function tableName( ?object $database = null ): string {
		$database = self::database( $database );

		return $database->prefix . 'ys_helcim_operations';
	}

	/** @param object|null $database wpdb-compatible database object. */
	public static function outboxTableName( ?object $database = null ): string {
		$database = self::database( $database );

		return $database->prefix . 'ys_helcim_outbox';
	}

	/** @param object|null $database wpdb-compatible database object. */
	public static function createSql( ?object $database = null ): string {
		$database = self::database( $database );
		$table    = self::tableName( $database );
		$collate  = $database->get_charset_collate();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			operation_uuid char(36) NOT NULL,
			idempotency_key char(36) NOT NULL,
			scope_key char(69) NOT NULL,
			active_scope_key char(69) DEFAULT NULL,
			operation_type varchar(20) NOT NULL,
			gateway varchar(50) NOT NULL,
			order_id bigint(20) unsigned NOT NULL,
			transaction_id bigint(20) unsigned NOT NULL,
			transaction_uuid varchar(191) NOT NULL,
			parent_operation_uuid char(36) DEFAULT NULL,
			amount bigint(20) unsigned NOT NULL,
			currency char(3) NOT NULL,
			payment_mode varchar(10) NOT NULL,
			remote_status varchar(20) NOT NULL,
			local_status varchar(20) NOT NULL,
			source_vendor_transaction_id varchar(64) DEFAULT NULL,
			vendor_transaction_id varchar(64) DEFAULT NULL,
			provider_correlation_id varchar(64) DEFAULT NULL,
			request_fingerprint char(64) NOT NULL,
			remote_error_code varchar(100) DEFAULT NULL,
			remote_error_message text NULL,
			local_error_code varchar(100) DEFAULT NULL,
			local_error_message text NULL,
			local_payload longtext NULL,
			local_payload_hash char(64) DEFAULT NULL,
			local_transaction_id bigint(20) unsigned DEFAULT NULL,
			local_claimed_at datetime DEFAULT NULL,
			local_recorded_at datetime DEFAULT NULL,
			local_applied_at datetime DEFAULT NULL,
			encrypted_material longtext NULL,
			material_expires_at datetime DEFAULT NULL,
			confirm_token_hash char(64) DEFAULT NULL,
			confirm_token_expires_at datetime DEFAULT NULL,
			recovery_attempt_count smallint(5) unsigned NOT NULL DEFAULT 0,
			next_recovery_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			resolved_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY operation_uuid (operation_uuid),
			UNIQUE KEY idempotency_key (idempotency_key),
			UNIQUE KEY active_scope_key (active_scope_key),
			UNIQUE KEY provider_correlation_id (provider_correlation_id),
			UNIQUE KEY vendor_transaction_id (vendor_transaction_id),
			UNIQUE KEY parent_operation_type (parent_operation_uuid, operation_type),
			UNIQUE KEY local_transaction_id (local_transaction_id),
			KEY transaction_id (transaction_id),
			KEY order_id (order_id),
			KEY parent_operation_uuid (parent_operation_uuid),
			KEY material_expires_at (material_expires_at),
			KEY hosted_recovery_due (operation_type, gateway, next_recovery_at, recovery_attempt_count),
			KEY remote_local_status (remote_status, local_status)
		) ENGINE=InnoDB {$collate};";
	}

	/** @param object|null $database wpdb-compatible database object. */
	public static function outboxCreateSql( ?object $database = null ): string {
		$database = self::database( $database );
		$table    = self::outboxTableName( $database );
		$collate  = $database->get_charset_collate();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			operation_uuid char(36) NOT NULL,
			effect_type varchar(64) NOT NULL,
			effect_class varchar(20) NOT NULL,
			sequence smallint(5) unsigned NOT NULL DEFAULT 0,
			payload longtext NOT NULL,
			payload_hash char(64) NOT NULL,
			status varchar(20) NOT NULL,
			attempt_count int(10) unsigned NOT NULL DEFAULT 0,
			claim_token char(36) DEFAULT NULL,
			available_at datetime NOT NULL,
			claimed_at datetime DEFAULT NULL,
			completed_at datetime DEFAULT NULL,
			result_hash char(64) DEFAULT NULL,
			last_error_code varchar(100) DEFAULT NULL,
			last_error_message text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY operation_effect (operation_uuid, effect_type),
			UNIQUE KEY claim_token (claim_token),
			KEY ready_effects (status, available_at, sequence, id),
			KEY operation_uuid (operation_uuid)
		) ENGINE=InnoDB {$collate};";
	}

	/** @param object|null $database wpdb-compatible database object. */
	public static function install( ?object $database = null ): bool {
		$database = self::database( $database );
		if ( property_exists( $database, 'last_error' ) ) {
			$database->last_error = '';
		}

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		dbDelta( self::createSql( $database ) );
		dbDelta( self::outboxCreateSql( $database ) );
		dbDelta( YSHelcimWebhookReceiptRepository::createSql( $database ) );
		if ( '' !== (string) ( $database->last_error ?? '' ) || ! self::hasRequiredSchema( $database ) ) {
			YSHelcimLogger::error( 'Operation journal schema installation failed' );
			return false;
		}

		update_option( self::OPTION_NAME, self::VERSION, false );
		return true;
	}

	/**
	 * WordPress activation-hook adapter.
	 *
	 * WordPress passes the multisite network flag as the first callback argument;
	 * it is intentionally separate from the injectable install database.
	 */
	public static function activate( bool $network_wide = false ): void {
		unset( $network_wide );
		self::install();
	}

	/** @param object|null $database wpdb-compatible database object. */
	public static function maybeUpgrade( ?object $database = null ): bool {
		if ( self::VERSION !== (string) get_option( self::OPTION_NAME, '' ) ) {
			return self::install( $database );
		}

		return true;
	}

	/** Run an explicit physical-schema health check without performing a migration. */
	public static function verifyHealth( ?object $database = null ): bool {
		return self::hasRequiredSchema( self::database( $database ) );
	}

	/** Verify that dbDelta produced the concurrency-critical table and indexes. */
	private static function hasRequiredSchema( object $database ): bool {
		$operation_indexes = array(
			'operation_uuid'          => array( 'operation_uuid' ),
			'idempotency_key'         => array( 'idempotency_key' ),
			'active_scope_key'        => array( 'active_scope_key' ),
			'provider_correlation_id' => array( 'provider_correlation_id' ),
			'vendor_transaction_id'    => array( 'vendor_transaction_id' ),
			'parent_operation_type'   => array( 'parent_operation_uuid', 'operation_type' ),
			'local_transaction_id'    => array( 'local_transaction_id' ),
		);
		$outbox_indexes = array(
			'operation_effect' => array( 'operation_uuid', 'effect_type' ),
			'claim_token'      => array( 'claim_token' ),
		);
		$webhook_receipt_indexes = array(
			'receipt_key' => array( 'receipt_key' ),
		);

		return self::hasRequiredTable(
			$database,
			self::tableName( $database ),
			$operation_indexes,
			array( 'local_claimed_at', 'recovery_attempt_count', 'next_recovery_at' )
		)
			&& self::hasRequiredTable( $database, self::outboxTableName( $database ), $outbox_indexes )
			&& self::hasRequiredTable(
				$database,
				YSHelcimWebhookReceiptRepository::tableName( $database ),
				$webhook_receipt_indexes
			);
	}

	/** @param array<string,string[]> $required @param string[] $required_columns */
	private static function hasRequiredTable(
		object $database,
		string $table,
		array $required,
		array $required_columns = array()
	): bool {
		$like  = method_exists( $database, 'esc_like' ) ? $database->esc_like( $table ) : $table;
		$found = $database->get_var(
			$database->prepare( 'SHOW TABLES LIKE %s', $like )
		);

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

		$indexes = $database->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A );
		$valid_unique_indexes = array();
		$invalid_indexes      = array();
		foreach ( is_array( $indexes ) ? $indexes : array() as $index ) {
			if (
				isset( $index['Key_name'], $index['Column_name'], $index['Non_unique'], $index['Seq_in_index'] ) &&
				'0' === (string) $index['Non_unique']
			) {
				$name     = (string) $index['Key_name'];
				$sequence = (int) $index['Seq_in_index'];
				if (
					$sequence <= 0 ||
					( null !== ( $index['Sub_part'] ?? null ) && '' !== (string) $index['Sub_part'] ) ||
					isset( $valid_unique_indexes[ $name ][ $sequence ] )
				) {
					$invalid_indexes[ $name ] = true;
					continue;
				}
				$valid_unique_indexes[ $name ][ $sequence ] = (string) $index['Column_name'];
			}
		}

		foreach ( $required as $index_name => $columns ) {
			$actual = $valid_unique_indexes[ $index_name ] ?? array();
			ksort( $actual, SORT_NUMERIC );
			if ( isset( $invalid_indexes[ $index_name ] ) || $columns !== array_values( $actual ) ) {
				return false;
			}
		}

		if ( ! empty( $required_columns ) ) {
			$columns = $database->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A );
			$found_columns = array();
			foreach ( is_array( $columns ) ? $columns : array() as $column ) {
				if ( isset( $column['Field'] ) ) {
					$found_columns[] = (string) $column['Field'];
				}
			}
			foreach ( $required_columns as $required_column ) {
				if ( ! in_array( $required_column, $found_columns, true ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/** @param object|null $database @return object */
	private static function database( ?object $database ): object {
		if ( null !== $database ) {
			return $database;
		}

		global $wpdb;
		return $wpdb;
	}
}
