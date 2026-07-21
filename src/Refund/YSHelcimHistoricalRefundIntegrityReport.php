<?php
/**
 * Bounded, non-PII result for the historical refund integrity gate.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Refund;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable report that callers can use as a deployment gate.
 */
final class YSHelcimHistoricalRefundIntegrityReport implements \JsonSerializable {

	private bool $scan_complete;

	private int $blocker_count;

	/** @var array<int,array<string,int|string>> */
	private array $issues;

	/** @var array{orders:int,transactions:int,charges:int,refunds:int} */
	private array $scanned;

	private int $issue_limit;

	/**
	 * @param array<int,array<string,int|string>>                 $issues
	 * @param array{orders:int,transactions:int,charges:int,refunds:int} $scanned
	 */
	private function __construct(
		bool $scan_complete,
		int $blocker_count,
		array $issues,
		array $scanned,
		int $issue_limit
	) {
		$this->scan_complete = $scan_complete;
		$this->blocker_count = max( 0, $blocker_count );
		$this->issue_limit   = max( 1, min( 200, $issue_limit ) );
		$this->issues        = array_slice( array_values( $issues ), 0, $this->issue_limit );
		$this->scanned       = array(
			'orders'       => max( 0, (int) ( $scanned['orders'] ?? 0 ) ),
			'transactions' => max( 0, (int) ( $scanned['transactions'] ?? 0 ) ),
			'charges'      => max( 0, (int) ( $scanned['charges'] ?? 0 ) ),
			'refunds'      => max( 0, (int) ( $scanned['refunds'] ?? 0 ) ),
		);
	}

	/**
	 * @param array<int,array<string,int|string>>                 $issues
	 * @param array{orders:int,transactions:int,charges:int,refunds:int} $scanned
	 */
	public static function complete( int $blocker_count, array $issues, array $scanned, int $issue_limit ): self {
		return new self( true, $blocker_count, $issues, $scanned, $issue_limit );
	}

	/** A storage error is itself one deployment blocker. */
	public static function unavailable( int $issue_limit ): self {
		return new self(
			false,
			1,
			array( array( 'code' => 'storage_unavailable' ) ),
			array(
				'orders'       => 0,
				'transactions' => 0,
				'charges'      => 0,
				'refunds'      => 0,
			),
			$issue_limit
		);
	}

	public function isDeploymentAllowed(): bool {
		return $this->scan_complete && 0 === $this->blocker_count;
	}

	public function blockerCount(): int {
		return $this->blocker_count;
	}

	/** @return array<string,mixed> */
	public function toArray(): array {
		$result = $this->scan_complete
			? ( 0 === $this->blocker_count ? 'pass' : 'blocked' )
			: 'unavailable';

		return array(
			'version'            => 1,
			'result'             => $result,
			'scan_complete'      => $this->scan_complete,
			'deployment_allowed' => $this->isDeploymentAllowed(),
			'blocker_count'      => $this->blocker_count,
			'issues_truncated'   => $this->blocker_count > count( $this->issues ),
			'issue_limit'        => $this->issue_limit,
			'scanned'            => $this->scanned,
			'issues'             => $this->issues,
		);
	}

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		return $this->toArray();
	}
}
