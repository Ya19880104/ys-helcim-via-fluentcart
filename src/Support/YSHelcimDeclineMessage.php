<?php
/**
 * Shopper-facing wording for a Helcim card decline.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns Helcim's decline text into one actionable category.
 *
 * Fraud, lost/stolen and similar issuer reasons are deliberately reported to the
 * shopper only as a bank decline; the exact provider text goes to the store's
 * order log instead. The same ordered patterns are mirrored in both checkout
 * scripts, which classify Helcim.js and HelcimPay.js browser results.
 */
final class YSHelcimDeclineMessage {

	public const GENERIC = 'generic';

	/** Ordered: the first matching category wins. */
	private const PATTERNS = array(
		'store'        => '/APPL TYPE|INVALID TERM|INVALID MERCHANT|AMOUNT ERROR|SVC LMT|SERVICE LIMIT|DUPLICATE|\bDUP\b/i',
		'cvv'          => '/\bCV[VCF]2?\b|\bCID\b|SECURITY CODE/i',
		'expired'      => '/EXPIRED CARD|CARD (?:HAS )?EXPIRED|EXPIRY|EXPIRATION/i',
		'invalid_card' => '/\bINVALID CARD\b|INVALID (?:CARD )?NUMBER|INVALID ACCOUNT|NO SUCH (?:CARD|ACCOUNT|ISSUER)/i',
		'funds'        => '/INSUFFICIENT|\bNSF\b|EXCEEDS BAL|EXCEEDS (?:THE )?(?:CREDIT |WITHDRAWAL )?LIMIT|OVER (?:CREDIT )?LIMIT/i',
		'address'      => '/\bAVS\b|ADDRESS|POSTAL|\bZIP\b/i',
		'retry'        => '/PLEASE RETRY|RE-?ENTER|NETWORK ERROR|SYSTEM ERROR|TIME ?OUT|TRY AGAIN/i',
		'issuer'       => '/FRAUD|PICK ?UP|LOST|STOLEN|RESTRICTED|HONOU?R|REFER|\bCALL\b|SECURITY VIOLATION|NOT PERMITTED|NOT ALLOWED|BLOCKED/i',
	);

	/** @return list<string> Every category, the generic fallback last. */
	public static function categories(): array {
		return array_merge( array_keys( self::PATTERNS ), array( self::GENERIC ) );
	}

	public static function category( ?string $provider_text ): string {
		$text = self::reason( $provider_text );
		if ( null === $text ) {
			return self::GENERIC;
		}
		foreach ( self::PATTERNS as $category => $pattern ) {
			if ( 1 === preg_match( $pattern, $text ) ) {
				return $category;
			}
		}

		return self::GENERIC;
	}

	/** Customer-safe explanation that always states no payment was taken. */
	public static function shopperMessage( string $category ): string {
		switch ( $category ) {
			case 'store':
				return __( 'This payment could not be processed right now. No payment was taken. Please try again later or contact the store.', 'ys-helcim-via-fluentcart' );
			case 'cvv':
				return __( 'The card security code (CVV) did not match. No payment was taken. Please check the code and try again.', 'ys-helcim-via-fluentcart' );
			case 'expired':
				return __( 'The card has expired or the expiry date is incorrect. No payment was taken. Please check the date or use a different card.', 'ys-helcim-via-fluentcart' );
			case 'invalid_card':
				return __( 'The card number is not valid. No payment was taken. Please check the number or use a different card.', 'ys-helcim-via-fluentcart' );
			case 'funds':
				return __( 'The card does not have enough available funds or credit. No payment was taken. Please use a different card.', 'ys-helcim-via-fluentcart' );
			case 'address':
				return __( 'The billing address does not match the card. No payment was taken. Please check the billing address and try again.', 'ys-helcim-via-fluentcart' );
			case 'retry':
				return __( 'The card could not be processed right now. No payment was taken. Please try again in a moment.', 'ys-helcim-via-fluentcart' );
			case 'issuer':
				return __( 'Your bank declined this payment. No payment was taken. Please contact your bank or use a different card.', 'ys-helcim-via-fluentcart' );
			default:
				return __( 'The card was declined and no payment was taken. Please check the details or use a different card.', 'ys-helcim-via-fluentcart' );
		}
	}

	/** Single-line provider decline text safe to store in the order log, or null. */
	public static function reason( mixed $provider_text ): ?string {
		if ( ! is_string( $provider_text ) ) {
			return null;
		}
		$text = trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $provider_text ) );
		// 訂單活動紀錄在 FluentCart 後台以 innerHTML 呈現：與 log 共用同一套去標籤＋卡號／憑證遮罩。
		$text = trim( YSHelcimSanitizer::errorText( $text, 1200 ) );
		if ( '' === $text ) {
			return null;
		}

		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 300 ) : substr( $text, 0, 300 );
	}
}
