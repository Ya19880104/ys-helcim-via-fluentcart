<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Refund;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimHistoricalRefundIntegrityReport;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimHistoricalRefundIntegrityScanner;
use YangSheep\Helcim\FluentCart\Tests\Doubles\HistoricalRefundIntegrityWpdb;

final class HistoricalRefundIntegrityScannerTest extends TestCase
{
    private HistoricalRefundIntegrityWpdb $database;

    protected function setUp(): void
    {
        $this->database = new HistoricalRefundIntegrityWpdb();
        $this->seedCanonicalOrder();
    }

    public function testCleanHistoryPassesFromOneReadOnlyRepeatableReadSnapshot(): void
    {
        $before = $this->database->dataSnapshot();

        $report = $this->scanner()->scan();

        self::assertInstanceOf(YSHelcimHistoricalRefundIntegrityReport::class, $report);
        self::assertTrue($report->isDeploymentAllowed());
        self::assertSame(0, $report->blockerCount());
        self::assertSame([
            'version' => 1,
            'result' => 'pass',
            'scan_complete' => true,
            'deployment_allowed' => true,
            'blocker_count' => 0,
            'issues_truncated' => false,
            'issue_limit' => 100,
            'scanned' => [
                'orders' => 1,
                'transactions' => 2,
                'charges' => 1,
                'refunds' => 1,
            ],
            'issues' => [],
        ], $report->toArray());
        self::assertSame([
            'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
            'START TRANSACTION READ ONLY',
            'COMMIT',
        ], $this->database->commands);
        self::assertSame($before, $this->database->dataSnapshot());
        self::assertFalse($this->database->inTransaction());
        foreach ($this->database->reads as $query) {
            self::assertStringNotContainsString('FOR UPDATE', strtoupper($query));
        }
    }

    #[DataProvider('invalidReceiptProvider')]
    public function testMissingOrMalformedRefundReceiptBlocksDeployment(mixed $receipt, string $expectedCode): void
    {
        $this->mutateTransaction(21, ['vendor_charge_id' => $receipt]);

        $report = $this->scanner()->scan();

        self::assertFalse($report->isDeploymentAllowed());
        self::assertContains($expectedCode, $this->issueCodes($report));
        self::assertSame(21, $this->issueByCode($report, $expectedCode)['transaction_id']);
    }

    /** @return array<string,array{mixed,string}> */
    public static function invalidReceiptProvider(): array
    {
        return [
            'empty legacy receipt' => ['', 'missing_refund_vendor_id'],
            'null legacy receipt' => [null, 'missing_refund_vendor_id'],
            'non-numeric receipt' => ['refund-51177123', 'invalid_refund_vendor_id'],
            'whitespace padded receipt' => [' 51177123 ', 'invalid_refund_vendor_id'],
            'zero receipt' => ['0', 'invalid_refund_vendor_id'],
        ];
    }

    public function testDuplicateProviderRefundReceiptInOneModeIsReportedWithoutExposingIt(): void
    {
        $this->seedCanonicalOrder(11, 30, 31, 'ys_helcim_js', 'test', '51177123');

        $report = $this->scanner()->scan();
        $serialized = wp_json_encode($report->toArray());

        self::assertContains('duplicate_refund_vendor_id', $this->issueCodes($report));
        self::assertStringNotContainsString('51177123', (string) $serialized);
        self::assertSame([
            'code' => 'duplicate_refund_vendor_id',
            'transaction_id' => 21,
            'related_transaction_id' => 31,
            'occurrence_count' => 2,
        ], $this->issueByCode($report, 'duplicate_refund_vendor_id'));
    }

    public function testProviderReceiptMayRepeatAcrossIsolatedTestAndLiveModes(): void
    {
        $this->seedCanonicalOrder(11, 30, 31, 'ys_helcim_js', 'live', '51177123');

        $report = $this->scanner()->scan();

        self::assertNotContains('duplicate_refund_vendor_id', $this->issueCodes($report));
    }

    public function testRefundWhoseHelcimParentUsesAnotherProviderIsBlocked(): void
    {
        $this->mutateTransaction(21, ['payment_method' => 'stripe']);

        $report = $this->scanner()->scan();

        self::assertContains('unknown_refund_gateway', $this->issueCodes($report));
        self::assertContains('refund_gateway_mismatch', $this->issueCodes($report));
    }

    public function testCrossHelcimGatewayMismatchIsBlocked(): void
    {
        $this->mutateTransaction(21, ['payment_method' => 'ys_helcim_js']);

        self::assertContains('refund_gateway_mismatch', $this->issueCodes($this->scanner()->scan()));
    }

    public function testKnownHelcimOrderKeepsCorruptedTransactionGatewaysInScope(): void
    {
        $this->mutateTransaction(20, ['payment_method' => 'stripe']);
        $this->mutateTransaction(21, ['payment_method' => 'stripe']);

        $report = $this->scanner()->scan();

        self::assertContains('unknown_refund_gateway', $this->issueCodes($report));
        self::assertContains('unknown_parent_gateway', $this->issueCodes($report));
        self::assertFalse($report->isDeploymentAllowed());
    }

    #[DataProvider('unknownIdentityProvider')]
    public function testUnknownModeOrParentIsBlocked(array $changes, string $expectedCode): void
    {
        $this->mutateTransaction(21, $changes);

        self::assertContains($expectedCode, $this->issueCodes($this->scanner()->scan()));
    }

    /** @return array<string,array{array<string,mixed>,string}> */
    public static function unknownIdentityProvider(): array
    {
        return [
            'unknown refund mode' => [['payment_mode' => 'sandbox'], 'unknown_refund_mode'],
            'missing parent metadata' => [['meta' => '{}'], 'unknown_refund_parent'],
            'malformed parent metadata' => [['meta' => '{broken'], 'unknown_refund_parent'],
            'missing parent row' => [['meta' => '{"parent_id":999}'], 'unknown_refund_parent'],
        ];
    }

    public function testUnknownParentGatewayAndModeAreBlocked(): void
    {
        $this->mutateTransaction(20, ['payment_method' => 'stripe', 'payment_mode' => 'sandbox']);

        $report = $this->scanner()->scan();

        self::assertContains('unknown_parent_gateway', $this->issueCodes($report));
        self::assertContains('unknown_parent_mode', $this->issueCodes($report));
    }

    #[DataProvider('accountingDriftProvider')]
    public function testParentOrderAndChargeAccountingDriftBlocksDeployment(
        string $table,
        int $id,
        array $changes,
        string $expectedCode
    ): void {
        if ('wp_fct_order_transactions' === $table) {
            $this->mutateTransaction($id, $changes);
        } else {
            $row = $this->database->row($table, $id);
            $this->database->replace($table, $id, array_merge($row, $changes));
        }

        self::assertContains($expectedCode, $this->issueCodes($this->scanner()->scan()));
    }

    /** @return array<string,array{string,int,array<string,mixed>,string}> */
    public static function accountingDriftProvider(): array
    {
        return [
            'parent meta differs from refund rows' => [
                'wp_fct_order_transactions', 20, ['meta' => '{"refunded_total":999}'], 'parent_refunded_total_mismatch',
            ],
            'refunds exceed charge' => [
                'wp_fct_order_transactions', 20, ['total' => '500'], 'charge_refund_total_exceeded',
            ],
            'order refund differs from refund rows' => [
                'wp_fct_orders', 10, ['total_refund' => '999'], 'order_refunded_total_mismatch',
            ],
            'order paid differs from succeeded charges' => [
                'wp_fct_orders', 10, ['total_paid' => '4999'], 'order_paid_total_mismatch',
            ],
            'order refund exceeds paid' => [
                'wp_fct_orders', 10, ['total_paid' => '500'], 'order_refund_total_exceeded',
            ],
        ];
    }

    public function testMissingOrderIsBlockedWithoutAbortingTheRemainingScan(): void
    {
        $this->database->seed('wp_fct_order_transactions', [
            'id' => 40,
            'order_id' => 999,
            'order_type' => 'payment',
            'transaction_type' => 'charge',
            'vendor_charge_id' => '61177061',
            'payment_method' => 'ys_helcim_js',
            'payment_mode' => 'test',
            'status' => 'succeeded',
            'currency' => 'USD',
            'total' => '100',
            'meta' => '{}',
        ]);

        $report = $this->scanner(100, 1)->scan();

        self::assertContains('unknown_order', $this->issueCodes($report));
        self::assertSame(2, $report->toArray()['scanned']['orders']);
    }

    public function testZeroDefaultOrderIdIsIncludedWithoutUnsignedNegativeComparison(): void
    {
        $this->database->seed('wp_fct_order_transactions', [
            'id' => 41,
            'order_id' => 0,
            'order_type' => 'payment',
            'transaction_type' => 'refund',
            'vendor_charge_id' => '',
            'payment_method' => 'ys_helcim_js',
            'payment_mode' => 'test',
            'status' => 'refunded',
            'currency' => 'USD',
            'total' => '100',
            'meta' => '{}',
        ]);

        $report = $this->scanner()->scan();

        self::assertContains('unknown_order', $this->issueCodes($report));
        self::assertContains('missing_refund_vendor_id', $this->issueCodes($report));
        self::assertStringContainsString('order_id >= %d', $this->database->reads[0]);
    }

    public function testIssueListIsBoundedButBlockerCountCoversTheWholeSite(): void
    {
        for ($index = 0; $index < 4; ++$index) {
            $orderId = 20 + $index;
            $chargeId = 100 + ($index * 2);
            $refundId = $chargeId + 1;
            $this->seedCanonicalOrder($orderId, $chargeId, $refundId, 'ys_helcim_js', 'test', '');
        }

        $report = $this->scanner(2, 2)->scan();
        $payload = $report->toArray();

        self::assertGreaterThanOrEqual(4, $report->blockerCount());
        self::assertCount(2, $payload['issues']);
        self::assertTrue($payload['issues_truncated']);
        self::assertSame(5, $payload['scanned']['orders']);
    }

    #[DataProvider('storageFailureProvider')]
    public function testAnyDatabaseFailureReturnsAnIncompleteFailClosedReport(string $failure): void
    {
        if ('read' === $failure) {
            $this->database->failReadContaining = 'FROM `wp_fct_orders`';
        } elseif ('throw' === $failure) {
            $this->database->throwReadContaining = 'duplicate_count';
        } else {
            $this->database->{$failure} = true;
        }

        $report = $this->scanner()->scan();
        $payload = $report->toArray();

        self::assertFalse($report->isDeploymentAllowed());
        self::assertSame(1, $report->blockerCount());
        self::assertSame('unavailable', $payload['result']);
        self::assertFalse($payload['scan_complete']);
        self::assertSame([['code' => 'storage_unavailable']], $payload['issues']);
        self::assertSame('ROLLBACK', end($this->database->commands));
    }

    /** @return array<string,array{string}> */
    public static function storageFailureProvider(): array
    {
        return [
            'isolation' => ['failIsolation'],
            'start' => ['failStart'],
            'read error' => ['read'],
            'read exception' => ['throw'],
            'commit' => ['failCommit'],
        ];
    }

    public function testEmptySiteIsACompletePass(): void
    {
        $database = new HistoricalRefundIntegrityWpdb();

        $report = (new YSHelcimHistoricalRefundIntegrityScanner($database))->scan();

        self::assertTrue($report->isDeploymentAllowed());
        self::assertSame(0, $report->toArray()['scanned']['orders']);
    }

    private function scanner(int $issueLimit = 100, int $pageSize = 200): YSHelcimHistoricalRefundIntegrityScanner
    {
        return new YSHelcimHistoricalRefundIntegrityScanner($this->database, $issueLimit, $pageSize);
    }

    /** @return string[] */
    private function issueCodes(YSHelcimHistoricalRefundIntegrityReport $report): array
    {
        return array_column($report->toArray()['issues'], 'code');
    }

    /** @return array<string,mixed> */
    private function issueByCode(YSHelcimHistoricalRefundIntegrityReport $report, string $code): array
    {
        foreach ($report->toArray()['issues'] as $issue) {
            if ($code === $issue['code']) {
                return $issue;
            }
        }

        self::fail('Missing issue: ' . $code);
    }

    /** @param array<string,mixed> $changes */
    private function mutateTransaction(int $id, array $changes): void
    {
        $row = $this->database->row('wp_fct_order_transactions', $id);
        $this->database->replace('wp_fct_order_transactions', $id, array_merge($row, $changes));
    }

    private function seedCanonicalOrder(
        int $orderId = 10,
        int $chargeId = 20,
        int $refundId = 21,
        string $gateway = 'ys_helcim',
        string $mode = 'test',
        string $refundReceipt = '51177123'
    ): void {
        $this->database->seed('wp_fct_orders', [
            'id' => $orderId,
            'type' => 'payment',
            'mode' => $mode,
            'payment_method' => $gateway,
            'payment_status' => 'partially_refunded',
            'currency' => 'USD',
            'total_amount' => '5000',
            'total_paid' => '5000',
            'total_refund' => '1000',
        ]);
        $this->database->seed('wp_fct_order_transactions', [
            'id' => $chargeId,
            'order_id' => $orderId,
            'order_type' => 'payment',
            'transaction_type' => 'charge',
            'vendor_charge_id' => (string) (51177061 + $chargeId),
            'payment_method' => $gateway,
            'payment_mode' => $mode,
            'status' => 'succeeded',
            'currency' => 'USD',
            'total' => '5000',
            'meta' => wp_json_encode(['refunded_total' => 1000]),
        ]);
        $this->database->seed('wp_fct_order_transactions', [
            'id' => $refundId,
            'order_id' => $orderId,
            'order_type' => 'payment',
            'transaction_type' => 'refund',
            'vendor_charge_id' => $refundReceipt,
            'payment_method' => $gateway,
            'payment_mode' => $mode,
            'status' => 'refunded',
            'currency' => 'USD',
            'total' => '1000',
            'meta' => wp_json_encode(['parent_id' => $chargeId]),
        ]);
    }
}
