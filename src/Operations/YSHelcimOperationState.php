<?php
/**
 * Operation journal state rules.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Operations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure transition policy for remote and local operation outcomes.
 */
final class YSHelcimOperationState {

	public const REMOTE_CREATED       = 'created';
	public const REMOTE_PROCESSING    = 'processing';
	public const REMOTE_SUCCEEDED     = 'succeeded';
	public const REMOTE_DECLINED      = 'declined';
	public const REMOTE_FAILED        = 'failed';
	public const REMOTE_INDETERMINATE = 'indeterminate';
	public const REMOTE_CANCELED      = 'canceled';
	public const REMOTE_EXPIRED       = 'expired';

	public const LOCAL_PENDING = 'pending';
	public const LOCAL_APPLYING = 'applying';
	public const LOCAL_RECORDED = 'recorded';
	public const LOCAL_APPLIED = 'applied';
	public const LOCAL_FAILED  = 'failed';

	/** @var array<string, string[]> */
	private const REMOTE_TRANSITIONS = array(
		self::REMOTE_CREATED       => array(
			self::REMOTE_PROCESSING,
			self::REMOTE_FAILED,
			self::REMOTE_CANCELED,
			self::REMOTE_EXPIRED,
		),
		self::REMOTE_PROCESSING    => array(
			self::REMOTE_SUCCEEDED,
			self::REMOTE_DECLINED,
			self::REMOTE_FAILED,
			self::REMOTE_INDETERMINATE,
		),
		self::REMOTE_INDETERMINATE => array(
			self::REMOTE_SUCCEEDED,
			self::REMOTE_DECLINED,
			self::REMOTE_FAILED,
		),
		self::REMOTE_SUCCEEDED     => array(),
		self::REMOTE_DECLINED      => array(),
		self::REMOTE_FAILED        => array(),
		self::REMOTE_CANCELED      => array(),
		self::REMOTE_EXPIRED       => array(),
	);

	/** @var array<string, string[]> */
	private const LOCAL_TRANSITIONS = array(
		self::LOCAL_PENDING  => array( self::LOCAL_APPLYING, self::LOCAL_FAILED ),
		self::LOCAL_APPLYING => array( self::LOCAL_RECORDED, self::LOCAL_APPLIED, self::LOCAL_FAILED ),
		self::LOCAL_RECORDED => array( self::LOCAL_APPLIED, self::LOCAL_FAILED ),
		self::LOCAL_FAILED   => array( self::LOCAL_APPLYING ),
		self::LOCAL_APPLIED  => array(),
	);

	/** @return string[] */
	public static function remoteStates(): array {
		return array_keys( self::REMOTE_TRANSITIONS );
	}

	public static function canTransitionRemote( string $from, string $to ): bool {
		return in_array( $to, self::REMOTE_TRANSITIONS[ $from ] ?? array(), true );
	}

	public static function canTransitionLocal( string $from, string $to ): bool {
		return in_array( $to, self::LOCAL_TRANSITIONS[ $from ] ?? array(), true );
	}

	/**
	 * A scope is reusable only after definite no-charge proof or full local apply.
	 */
	public static function shouldReleaseScope( string $remote_status, string $local_status ): bool {
		if ( self::REMOTE_SUCCEEDED === $remote_status ) {
			return self::LOCAL_APPLIED === $local_status;
		}

		return in_array(
			$remote_status,
			array(
				self::REMOTE_DECLINED,
				self::REMOTE_FAILED,
				self::REMOTE_CANCELED,
				self::REMOTE_EXPIRED,
			),
			true
		);
	}
}
