<?php
/**
 * Resolves a signed webhook proof to one durable purchase account binding.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Webhook;

use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationState;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimPurchaseOperation;
use YangSheep\Helcim\FluentCart\Support\YSHelcimDatabaseInteger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class YSHelcimWebhookOperationBindingResolver {

	/** @var callable */
	private $operation_reader;

	public function __construct( callable $operation_reader ) {
		$this->operation_reader = $operation_reader;
	}

	/**
	 * @return array{status:'matched',binding:array{gateway:string,mode:string}}|array{status:'unrelated'|'unavailable'|'conflict'}
	 */
	public function resolve( array $proof ): array {
		$uuid = self::uuid( $proof['invoiceNumber'] ?? null );
		if ( null === $uuid ) {
			return array( 'status' => 'unrelated' );
		}

		try {
			$row = ( $this->operation_reader )( $uuid );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return array( 'status' => 'unavailable' );
		}
		if ( is_wp_error( $row ) ) {
			return array( 'status' => 'unavailable' );
		}
		if ( null === $row ) {
			return array( 'status' => 'unrelated' );
		}
		if ( ! is_array( $row ) || ! hash_equals( $uuid, strtolower( (string) ( $row['operation_uuid'] ?? '' ) ) ) ) {
			return array( 'status' => 'conflict' );
		}

		$identity = array(
			'gateway'          => $row['gateway'] ?? null,
			'order_id'         => YSHelcimDatabaseInteger::positive( $row['order_id'] ?? null ),
			'transaction_id'   => YSHelcimDatabaseInteger::positive( $row['transaction_id'] ?? null ),
			'transaction_uuid' => $row['transaction_uuid'] ?? null,
			'amount'           => YSHelcimDatabaseInteger::positive( $row['amount'] ?? null ),
			'currency'         => $row['currency'] ?? null,
			'payment_mode'     => $row['payment_mode'] ?? null,
		);
		if (
			! is_string( $identity['gateway'] ) ||
			! is_int( $identity['order_id'] ) ||
			! is_int( $identity['transaction_id'] ) ||
			! is_string( $identity['transaction_uuid'] ) ||
			! is_int( $identity['amount'] ) ||
			! is_string( $identity['currency'] ) ||
			! is_string( $identity['payment_mode'] )
		) {
			return array( 'status' => 'conflict' );
		}

		$purchase = YSHelcimPurchaseOperation::fromTransaction( $identity );
		if (
			! $purchase instanceof YSHelcimPurchaseOperation ||
			! $purchase->matchesIdentityRow( $row ) ||
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
			return array( 'status' => 'conflict' );
		}

		return array(
			'status'  => 'matched',
			'binding' => array(
				'gateway' => (string) $identity['gateway'],
				'mode'    => (string) $identity['payment_mode'],
			),
		);
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
}
