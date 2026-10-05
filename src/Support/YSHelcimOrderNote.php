<?php
/**
 * Store-facing notes in the FluentCart order activity log.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class YSHelcimOrderNote {

	/** @return callable(int, string, string): void */
	public static function writer(): callable {
		return static function ( int $order_id, string $title, string $message ): void {
			$order = \FluentCart\App\Models\Order::query()->where( 'id', $order_id )->first();
			if ( is_object( $order ) && method_exists( $order, 'addLog' ) ) {
				$order->addLog( $title, $message, 'info', 'Helcim' );
			}
		};
	}

	/** Best effort: a note that cannot be written never changes a payment result. */
	public static function write( ?callable $writer, int $order_id, string $title, string $message ): void {
		if ( null === $writer || $order_id <= 0 ) {
			return;
		}
		try {
			$writer( $order_id, $title, $message );
		} catch ( \Throwable $exception ) {
			unset( $exception );
		}
	}

	/** Note text for a card decline, carrying Helcim's own reason for the store. */
	public static function declineMessage( string $reason, ?string $provider_transaction_id ): string {
		if ( null === $provider_transaction_id ) {
			/* translators: %s: Helcim's decline reason, e.g. "Transaction Declined: SUSPECTED FRAUD". */
			return sprintf( __( 'Helcim declined the card payment. Reason from Helcim: %s. No payment was taken, and the customer can try again.', 'ys-helcim-via-fluentcart' ), $reason );
		}

		/* translators: 1: Helcim transaction ID, 2: Helcim's decline reason, e.g. "Transaction Declined: SUSPECTED FRAUD". */
		return sprintf( __( 'Helcim declined the card payment (Helcim transaction %1$s). Reason from Helcim: %2$s. No payment was taken, and the customer can try again.', 'ys-helcim-via-fluentcart' ), $provider_transaction_id, $reason );
	}

	public static function declineTitle(): string {
		return __( 'Helcim declined a payment attempt', 'ys-helcim-via-fluentcart' );
	}
}
