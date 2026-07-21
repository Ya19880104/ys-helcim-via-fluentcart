<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Refund;

use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundOptionsQuery;
use YangSheep\Helcim\FluentCart\Tests\Doubles\RefundOptionsQueryWpdb;

final class RefundOptionsQueryTest extends TestCase
{
    private RefundOptionsQueryWpdb $database;
    private YSHelcimRefundOptionsQuery $query;

    protected function setUp(): void
    {
        $this->database = new RefundOptionsQueryWpdb();
        $this->query = new YSHelcimRefundOptionsQuery($this->database);
        $this->database->order = [
            'id' => '42',
            'currency' => 'USD',
            'total_paid' => '2100',
            'total_refund' => '500',
        ];
        $this->database->transactions = [[
            'id' => '7',
            'order_id' => '42',
            'payment_method' => 'ys_helcim',
            'status' => 'succeeded',
            'transaction_type' => 'charge',
            'total' => '2100',
            'meta' => '{"refunded_total":500}',
        ]];
        $this->database->items = [[
            'id' => '9',
            'order_id' => '42',
            'title' => 'Digital product',
            'quantity' => '2',
            'line_total' => '2100',
            'refund_total' => '500',
        ]];
        $this->database->operations = [[
            'order_id' => '42',
            'operation_type' => 'refund',
            'active_scope_key' => null,
            'remote_status' => 'succeeded',
            'local_status' => 'applied',
            'manual_reconciliation_required' => '1',
            'effect_status' => 'stock_reconciliation_required',
        ]];
    }

    public function testItReadsOneConsistentServerOwnedSnapshotAndProjectsDerivedRemainingAmounts(): void
    {
        $result = $this->query->load(42);

        self::assertIsArray($result);
        self::assertSame([
            'order' => $this->database->order,
            'transactions' => [[
                'id' => '7',
                'order_id' => '42',
                'payment_method' => 'ys_helcim',
                'status' => 'succeeded',
                'transaction_type' => 'charge',
                'remaining_refundable' => 1600,
            ]],
            'items' => [[
                'id' => '9',
                'title' => 'Digital product',
                'quantity' => 2,
                'refundable_quantity' => 2,
            ]],
            'operations' => $this->database->operations,
        ], $result);
        self::assertSame([
            'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
            'START TRANSACTION READ ONLY',
            'COMMIT',
        ], $this->database->commands);
        $operationQuery = array_values(array_filter(
            $this->database->preparedQueries,
            static fn (string $sql): bool => str_contains($sql, 'ys_helcim_refund_options_operations')
        ));
        self::assertCount(1, $operationQuery);
        self::assertStringContainsString('operations.operation_uuid', $operationQuery[0]);
    }

    public function testMalformedTransactionAccountingIsPreservedAsUnknownInsteadOfMadeRefundable(): void
    {
        $this->database->transactions[0]['meta'] = '{';

        $result = $this->query->load(42);

        self::assertIsArray($result);
        self::assertNull($result['transactions'][0]['remaining_refundable']);
    }

    public function testMissingOrderReturnsNullAndReadOrCommitFailureRollsBackWithSanitizedError(): void
    {
        $this->database->order = null;
        self::assertNull($this->query->load(42));

        $this->database->order = ['id' => '42'];
        $this->database->failRead = true;
        $failed = $this->query->load(42);
        self::assertInstanceOf(\WP_Error::class, $failed);
        self::assertSame('ys_helcim_refund_options_unavailable', $failed->get_error_code());
        self::assertSame('ROLLBACK', end($this->database->commands));

        $this->database->failRead = false;
        $this->database->failCommit = true;
        $commit = $this->query->load(42);
        self::assertInstanceOf(\WP_Error::class, $commit);
        self::assertSame('ROLLBACK', end($this->database->commands));
    }

    public function testInvalidOrderNeverTouchesStorage(): void
    {
        $result = $this->query->load(0);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_invalid_order', $result->get_error_code());
        self::assertSame([], $this->database->commands);
    }
}
