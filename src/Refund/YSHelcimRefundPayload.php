<?php
/**
 * Canonical local side-effect payload for a remote-first refund.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Refund;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Binds one provider operation to one immutable set of local refund effects.
 */
final class YSHelcimRefundPayload {

	/** @param array<string,mixed> $payload @return array<string,mixed> */
	public static function normalize( array $payload ): array {
		$allowed = array(
			'version',
			'reason',
			'item_ids',
			'manage_stock',
			'refunded_items',
			'cancel_subscription',
			'actor_user_id',
		);
		if ( array_diff( array_keys( $payload ), $allowed ) ) {
			throw new \InvalidArgumentException( 'Unknown refund side-effect field.' );
		}
		if ( array_key_exists( 'version', $payload ) && 1 !== $payload['version'] ) {
			throw new \InvalidArgumentException( 'Unsupported refund payload version.' );
		}

		$reason = $payload['reason'] ?? '';
		if ( ! is_string( $reason ) ) {
			throw new \InvalidArgumentException( 'Refund reason must be text.' );
		}
		$reason = substr( sanitize_textarea_field( $reason ), 0, 500 );

		$manage_stock = $payload['manage_stock'] ?? false;
		$cancel       = $payload['cancel_subscription'] ?? false;
		$actor_id     = $payload['actor_user_id'] ?? 0;
		if ( ! is_bool( $manage_stock ) || ! is_bool( $cancel ) || true === $cancel ) {
			throw new \InvalidArgumentException( 'Unsupported refund side effect.' );
		}
		if ( ! is_int( $actor_id ) || $actor_id < 0 ) {
			throw new \InvalidArgumentException( 'Invalid refund actor.' );
		}

		$item_ids = self::positiveIntegerList( $payload['item_ids'] ?? array() );
		$rows     = $payload['refunded_items'] ?? array();
		if ( ! is_array( $rows ) || count( $rows ) > 100 ) {
			throw new \InvalidArgumentException( 'Invalid refund stock rows.' );
		}

		$restores = array();
		foreach ( $rows as $row ) {
			if (
				! is_array( $row ) ||
				array_diff( array_keys( $row ), array( 'id', 'restore_quantity' ) ) ||
				! isset( $row['id'], $row['restore_quantity'] ) ||
				! is_int( $row['id'] ) ||
				! is_int( $row['restore_quantity'] ) ||
				$row['id'] <= 0 ||
				$row['restore_quantity'] <= 0 ||
				! in_array( $row['id'], $item_ids, true ) ||
				isset( $restores[ $row['id'] ] )
			) {
				throw new \InvalidArgumentException( 'Invalid refund stock row.' );
			}
			$restores[ $row['id'] ] = array(
				'id'               => $row['id'],
				'restore_quantity' => $row['restore_quantity'],
			);
		}
		ksort( $restores, SORT_NUMERIC );

		if ( $manage_stock && empty( $restores ) ) {
			throw new \InvalidArgumentException( 'Stock restoration requires item rows.' );
		}
		if ( ! $manage_stock && ! empty( $restores ) ) {
			throw new \InvalidArgumentException( 'Stock rows require stock restoration.' );
		}

		return array(
			'version'             => 1,
			'reason'              => $reason,
			'item_ids'            => $item_ids,
			'manage_stock'        => $manage_stock,
			'refunded_items'      => array_values( $restores ),
			'cancel_subscription' => false,
			'actor_user_id'       => $actor_id,
		);
	}

	/** @param array<string,mixed> $normalized */
	public static function hash( array $normalized ): string {
		$canonical = wp_json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $canonical ) ) {
			throw new \InvalidArgumentException( 'Refund payload could not be encoded.' );
		}

		return hash( 'sha256', $canonical );
	}

	/** @param mixed $values @return int[] */
	private static function positiveIntegerList( mixed $values ): array {
		if ( ! is_array( $values ) || count( $values ) > 100 ) {
			throw new \InvalidArgumentException( 'Invalid refund item list.' );
		}

		$unique = array();
		foreach ( $values as $value ) {
			if ( ! is_int( $value ) || $value <= 0 ) {
				throw new \InvalidArgumentException( 'Invalid refund item ID.' );
			}
			$unique[ $value ] = $value;
		}
		sort( $unique, SORT_NUMERIC );

		return array_values( $unique );
	}
}
