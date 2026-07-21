<?php
/**
 * Persistence boundary for positive indeterminate-refund resolution.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Refund;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface YSHelcimRefundResolutionStore {

	/** @return array<string,mixed>|\WP_Error|null */
	public function findOperation( string $operation_uuid );

	/** @return int|\WP_Error */
	public function promoteStaleProcessing( string $operation_uuid );

	/** @param array<string,mixed> $challenge @return bool|\WP_Error */
	public function createChallenge( array $challenge );

	/** @param array<string,mixed> $binding @return array<string,mixed>|\WP_Error|null */
	public function findResolutionReplay( array $binding );

	/** @param array<string,mixed> $resolution @return array<string,mixed>|\WP_Error */
	public function commitResolution( array $resolution );
}
