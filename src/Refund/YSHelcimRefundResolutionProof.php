<?php
/**
 * Read-only provider proof for resolving an indeterminate refund operation.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Refund;

use YangSheep\Helcim\FluentCart\Support\YSHelcimTransactionId;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts two exact provider reads into one canonical, re-checkable proof.
 */
final class YSHelcimRefundResolutionProof {

	public const ACTION = 'resolve_positive';

	/** Provider fields which, when present, explicitly bind a child to its source. */
	private const PARENT_FIELDS = array(
		'originalTransactionId',
		'parentTransactionId',
		'originalCardTransactionId',
	);

	/**
	 * @param array<string,mixed> $operation Stored operation plus resolution_candidate_id.
	 * @param array<string,mixed> $candidate Exact candidate GET response.
	 * @param array<string,mixed> $source    Exact source GET response.
	 * @param array<int,mixed>    $family    Every Helcim record listed for the source invoice number.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function verify( array $operation, array $candidate, array $source, array $family ) {
		$type         = strtolower( trim( (string) ( $operation['operation_type'] ?? '' ) ) );
		$currency     = strtoupper( trim( (string) ( $operation['currency'] ?? '' ) ) );
		$amount       = $operation['amount'] ?? null;
		$candidate_id = YSHelcimTransactionId::normalize( $operation['resolution_candidate_id'] ?? null );
		$source_id    = YSHelcimTransactionId::normalize( $operation['source_vendor_transaction_id'] ?? null );

		if (
			! in_array( $type, array( 'refund', 'reverse' ), true ) ||
			! is_int( $amount ) ||
			$amount <= 0 ||
			! in_array( $currency, array( 'USD', 'CAD' ), true ) ||
			null === $candidate_id ||
			null === $source_id ||
			$candidate_id === $source_id
		) {
			return self::mismatch();
		}

		$candidate_amount = YSHelcimProviderProof::amountToCents( $candidate['amount'] ?? null );
		$source_amount    = YSHelcimProviderProof::amountToCents( $source['amount'] ?? null );
		if (
			$candidate_id !== YSHelcimTransactionId::normalize( $candidate['transactionId'] ?? null ) ||
			'APPROVED' !== strtoupper( trim( (string) ( $candidate['status'] ?? '' ) ) ) ||
			$type !== strtolower( trim( (string) ( $candidate['type'] ?? '' ) ) ) ||
			$amount !== $candidate_amount ||
			$currency !== strtoupper( trim( (string) ( $candidate['currency'] ?? '' ) ) ) ||
			$source_id !== YSHelcimTransactionId::normalize( $source['transactionId'] ?? null ) ||
			'APPROVED' !== strtoupper( trim( (string) ( $source['status'] ?? '' ) ) ) ||
			! in_array( strtolower( trim( (string) ( $source['type'] ?? '' ) ) ), array( 'purchase', 'capture' ), true ) ||
			null === $source_amount ||
			( 'reverse' === $type ? $amount !== $source_amount : $amount > $source_amount ) ||
			$currency !== strtoupper( trim( (string) ( $source['currency'] ?? '' ) ) )
		) {
			return self::mismatch();
		}

		// Helcim 的 card-transactions 讀回沒有 originalTransactionId，正式站一律要 attestation；
		// 候選交易與來源交易之間唯一由 Helcim 提供的關聯是 invoiceNumber（退款／作廢繼承原 purchase 的值，
		// 同步路徑也用它當關聯鍵）。兩邊都必須有、而且不分大小寫相同；缺一或不同一律拒絕（fail-closed），
		// 別張訂單的同金額退款不可能被綁到這張訂單的結果不明作業。
		$candidate_invoice = self::invoiceNumber( $candidate['invoiceNumber'] ?? null );
		$source_invoice    = self::invoiceNumber( $source['invoiceNumber'] ?? null );
		if (
			null === $candidate_invoice ||
			null === $source_invoice ||
			! hash_equals( strtolower( $source_invoice ), strtolower( $candidate_invoice ) )
		) {
			return self::mismatch();
		}
		$invoice_key = strtolower( $source_invoice );

		// 同一 invoice 的整個交易家族：候選與來源都必須列得到，且不可有讓候選不能入帳的其他交易。
		if ( ! self::familyAllows( $family, $invoice_key, $type, $candidate_id, $source_id, $amount ) ) {
			return self::mismatch();
		}

		$parent_present = false;
		foreach ( self::PARENT_FIELDS as $field ) {
			if ( ! array_key_exists( $field, $candidate ) ) {
				continue;
			}
			$parent_present = true;
			if ( $source_id !== YSHelcimTransactionId::normalize( $candidate[ $field ] ) ) {
				return self::mismatch();
			}
		}

		$canonical = array(
			'version'                     => 2,
			'action'                      => self::ACTION,
			'operation_uuid'              => strtolower( (string) ( $operation['operation_uuid'] ?? '' ) ),
			'operation_type'              => $type,
			'gateway'                     => (string) ( $operation['gateway'] ?? '' ),
			'payment_mode'                => (string) ( $operation['payment_mode'] ?? '' ),
			'amount_cents'                => $amount,
			'currency'                    => $currency,
			'invoice_number'              => $invoice_key,
			'candidate_transaction_id'    => $candidate_id,
			'candidate_status'            => 'APPROVED',
			'candidate_type'              => $type,
			'candidate_amount_cents'      => $candidate_amount,
			'candidate_currency'          => $currency,
			'source_transaction_id'       => $source_id,
			'source_status'               => 'APPROVED',
			'source_type'                 => strtolower( trim( (string) $source['type'] ) ),
			'source_amount_cents'         => $source_amount,
			'source_currency'             => $currency,
			'provider_parent_field_found' => $parent_present,
		);
		$json      = wp_json_encode( $canonical, JSON_UNESCAPED_SLASHES );
		$digest    = is_string( $json ) ? hash( 'sha256', $json ) : '';
		if ( 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $digest ) ) {
			return self::mismatch();
		}

		return array(
			'action'                      => self::ACTION,
			'candidate_transaction_id'    => $candidate_id,
			'source_transaction_id'       => $source_id,
			'parent_attestation_required' => ! $parent_present,
			'proof_digest'                => $digest,
			'invoice_number'              => $source_invoice,
			'candidate'                   => array(
				'transaction_id' => $candidate_id,
				'status'         => 'APPROVED',
				'type'           => $type,
				'amount_cents'   => $candidate_amount,
				'currency'       => $currency,
				'invoice_number' => $candidate_invoice,
			),
			'source'                      => array(
				'transaction_id' => $source_id,
				'status'         => 'APPROVED',
				'type'           => $canonical['source_type'],
				'amount_cents'   => $source_amount,
				'currency'       => $currency,
				'invoice_number' => $source_invoice,
			),
		);
	}

	/**
	 * Helcim invoiceNumber 正規化：去頭尾空白後 1～128 個可列印 ASCII 字元，其他一律不算證據。
	 *
	 * 保留原本大小寫（畫面上與 Helcim 後台一致）；比對時一律轉小寫。
	 */
	public static function invoiceNumber( mixed $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = trim( $value );

		return 1 === preg_match( '/\A[\x20-\x7E]{1,128}\z/', $value ) ? $value : null;
	}

	/**
	 * 同 invoice 家族規則，與 YSHelcimProviderRefundSync::familyConflict() 相同：
	 *
	 * - 要解決的是作廢（reverse）：家族裡只要有任何退款紀錄（不論狀態），這筆 reverse 就可能是
	 *   「作廢了某筆退款」，無法確定作廢的是原付款 → 拒絕。
	 * - 要解決的是退款（refund）：家族裡有編號較大、同金額、已核准的作廢＝這筆退款已被作廢 → 拒絕。
	 *   本機無法證明 Helcim 作廢一筆退款之後，原退款紀錄是否仍回 APPROVED，所以不能只看單筆。
	 *
	 * 另外要求候選與來源都在清單裡（清單上的候選也必須是同類型、已核准）：清單空白、列錯 invoice
	 * 或與單筆讀回矛盾時，家族規則等於沒檢查，一律拒絕。
	 *
	 * @param array<int,mixed> $family
	 */
	private static function familyAllows(
		array $family,
		string $invoice_key,
		string $type,
		string $candidate_id,
		string $source_id,
		int $amount
	): bool {
		if ( ! array_is_list( $family ) ) {
			return false;
		}

		$candidate_listed = false;
		$source_listed    = false;
		foreach ( $family as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			$record_invoice = self::invoiceNumber( $record['invoiceNumber'] ?? null );
			if ( null === $record_invoice || $invoice_key !== strtolower( $record_invoice ) ) {
				continue;
			}
			$record_type   = strtolower( trim( (string) ( $record['type'] ?? '' ) ) );
			$record_status = strtoupper( trim( (string) ( $record['status'] ?? '' ) ) );
			if ( 'reverse' === $type && 'refund' === $record_type ) {
				return false;
			}
			$record_id = YSHelcimTransactionId::normalize( $record['transactionId'] ?? null );
			if ( null === $record_id ) {
				continue;
			}
			if ( $record_id === $candidate_id ) {
				if ( $type !== $record_type || 'APPROVED' !== $record_status ) {
					return false;
				}
				$candidate_listed = true;
				continue;
			}
			if ( $record_id === $source_id ) {
				$source_listed = 'APPROVED' === $record_status;
				continue;
			}
			if ( 'APPROVED' !== $record_status ) {
				continue;
			}
			if (
				'refund' === $type &&
				'reverse' === $record_type &&
				self::transactionIdGreater( $record_id, $candidate_id ) &&
				$amount === YSHelcimProviderProof::amountToCents( $record['amount'] ?? null )
			) {
				return false;
			}
		}

		return $candidate_listed && $source_listed;
	}

	private static function transactionIdGreater( string $left, string $right ): bool {
		return strlen( $left ) === strlen( $right ) ? strcmp( $left, $right ) > 0 : strlen( $left ) > strlen( $right );
	}

	private static function mismatch(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_resolution_proof_mismatch',
			__( 'The Helcim transactions do not provide exact proof for this refund resolution.', 'ys-helcim-via-fluentcart' )
		);
	}
}
