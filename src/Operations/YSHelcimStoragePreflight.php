<?php
/**
 * Transactional storage preflight for remote-first refunds.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Operations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Refuses provider mutations unless every table in the local atomic boundary is InnoDB.
 */
final class YSHelcimStoragePreflight {

	/** @var object wpdb-compatible database object. */
	private $database;

	public function __construct( ?object $database = null ) {
		if ( null === $database ) {
			global $wpdb;
			$database = $wpdb;
		}
		$this->database = $database;
	}

	/** @return true|\WP_Error */
	public function verify() {
		$prefix = (string) $this->database->prefix;
		$tables = array(
			$prefix . 'ys_helcim_operations',
			$prefix . 'ys_helcim_outbox',
			$prefix . 'fct_order_transactions',
			$prefix . 'fct_orders',
			$prefix . 'fct_order_items',
		);

		try {
			foreach ( $tables as $table ) {
				$engine = $this->database->get_var(
					$this->database->prepare(
						'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
						$table
					)
				);
				if ( ! is_string( $engine ) || 0 !== strcasecmp( 'InnoDB', $engine ) ) {
					return self::unsafeStorage();
				}
			}
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::unsafeStorage();
		}

		return true;
	}

	private static function unsafeStorage(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_storage_not_transactional',
			__( 'Refund storage is not transaction-safe. No provider request was sent.', 'ys-helcim-via-fluentcart' ),
			array( 'status' => 503 )
		);
	}
}
