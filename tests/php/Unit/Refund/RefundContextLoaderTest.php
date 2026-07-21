<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Refund;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundContextLoader;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundRequest;
use YangSheep\Helcim\FluentCart\Tests\Doubles\RefundContextWpdb;
use YangSheep\Helcim\FluentCart\Tests\Doubles\RefundRestRequest;

final class RefundContextLoaderTest extends TestCase
{
    private RefundContextWpdb $database;

    protected function setUp(): void
    {
        $this->database = new RefundContextWpdb();
        $this->seedCanonicalState();
    }

    public function testLoadsCanonicalRequestContextFromOneReadOnlyRepeatableReadSnapshot(): void
    {
        $before = $this->database->dataSnapshot();

        $result = ($this->loader())(20);

        self::assertSame([
            'order_id' => 10,
            'transaction_id' => 20,
            'transaction_uuid' => 'fc-transaction-123',
            'vendor_transaction_id' => '51177061',
            'gateway' => 'ys_helcim',
            'status' => 'succeeded',
            'transaction_type' => 'charge',
            'transaction_total' => 5000,
            'refunded_total' => 1000,
            'remaining_refundable' => 4000,
            'currency' => 'USD',
            'payment_mode' => 'test',
            'order_item_quantities' => [101 => 2, 102 => 1],
        ], $result);
        self::assertSame([
            'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
            'START TRANSACTION READ ONLY',
            'COMMIT',
        ], $this->database->commands);
        self::assertCount(4, $this->database->reads);
        self::assertFalse($this->database->inTransaction());
        self::assertSame($before, $this->database->dataSnapshot(), 'The context loader must remain read-only.');
        foreach ($this->database->reads as $query) {
            self::assertStringNotContainsString('FOR UPDATE', strtoupper($query));
        }
        self::assertStringContainsString('transaction_type, order_type, total, meta', $this->database->reads[0]);
        self::assertStringContainsString(
            'SELECT id, type, uuid, customer_id, currency, total_paid, total_refund, payment_status',
            $this->database->reads[1]
        );
        self::assertStringContainsString(
            'SELECT id, order_id, post_id, object_id, quantity, unit_price, subtotal, tax_amount, shipping_charge, discount_total, line_total, refund_total',
            $this->database->reads[3]
        );
    }

    public function testRepeatableReadPreventsAChangingLiveRowFromMixingSnapshots(): void
    {
        $mutated = false;
        $this->database->afterRead = static function (string $table, RefundContextWpdb $database) use (&$mutated): void {
            if ($mutated || $table !== 'wp_fct_order_transactions') {
                return;
            }
            $mutated = true;
            $order = $database->row('wp_fct_orders', 10);
            $order['total_refund'] = '4999';
            $database->replace('wp_fct_orders', $order);
        };

        $result = $this->loader()->load(20);

        self::assertIsArray($result);
        self::assertSame(4000, $result['remaining_refundable']);
        self::assertSame('4999', $this->database->row('wp_fct_orders', 10)['total_refund']);
    }

    #[DataProvider('invalidSourceRows')]
    public function testRejectsInvalidSourceIdentityAsAccountingDrift(string $field, mixed $value): void
    {
        $source = $this->database->row('wp_fct_order_transactions', 20);
        $source[$field] = $value;
        $this->database->replace('wp_fct_order_transactions', $source);

        $this->assertAccountingDrift($this->loader()->load(20));
    }

    /** @return array<string,array{string,mixed}> */
    public static function invalidSourceRows(): array
    {
        return [
            'not succeeded' => ['status', 'pending'],
            'not a charge' => ['transaction_type', 'refund'],
            'not Helcim' => ['payment_method', 'stripe'],
            'missing UUID' => ['uuid', ''],
            'invalid provider ID' => ['vendor_charge_id', 'legacy-1'],
            'unsupported currency' => ['currency', 'EUR'],
            'lowercase currency' => ['currency', 'usd'],
            'invalid mode' => ['payment_mode', 'sandbox'],
            'missing order type' => ['order_type', ''],
            'zero total' => ['total', '0'],
            'negative total' => ['total', '-1'],
        ];
    }

    public function testRejectsSourceAndOrderTypeMismatchBeforeProviderWork(): void
    {
        $order = $this->database->row('wp_fct_orders', 10);
        $order['type'] = 'subscription';
        $this->database->replace('wp_fct_orders', $order);

        $this->assertAccountingDrift($this->loader()->load(20));
    }

    public function testRejectsMalformedSourceMetaAsAccountingDrift(): void
    {
        $source = $this->database->row('wp_fct_order_transactions', 20);
        $source['meta'] = '{broken';
        $this->database->replace('wp_fct_order_transactions', $source);

        $this->assertAccountingDrift($this->loader()->load(20));
    }

    public function testRejectsSourceMetadataThatDisagreesWithItsRefundRows(): void
    {
        $source = $this->database->row('wp_fct_order_transactions', 20);
        $source['meta'] = wp_json_encode(['refunded_total' => 999]);
        $this->database->replace('wp_fct_order_transactions', $source);

        $this->assertAccountingDrift($this->loader()->load(20));
    }

    public function testRejectsOrderRefundTotalThatDisagreesWithAllRefundRows(): void
    {
        $order = $this->database->row('wp_fct_orders', 10);
        $order['total_refund'] = '999';
        $this->database->replace('wp_fct_orders', $order);

        $this->assertAccountingDrift($this->loader()->load(20));
    }

    public function testRejectsMalformedRefundMetadata(): void
    {
        $refund = $this->database->row('wp_fct_order_transactions', 21);
        $refund['meta'] = '{broken';
        $this->database->replace('wp_fct_order_transactions', $refund);

        $this->assertAccountingDrift($this->loader()->load(20));
    }

    #[DataProvider('invalidOrderTotals')]
    public function testRejectsInvalidOrderAccounting(string $field, mixed $value): void
    {
        $order = $this->database->row('wp_fct_orders', 10);
        $order[$field] = $value;
        $this->database->replace('wp_fct_orders', $order);

        $this->assertAccountingDrift($this->loader()->load(20));
    }

    /** @return array<string,array{string,mixed}> */
    public static function invalidOrderTotals(): array
    {
        return [
            'zero paid' => ['total_paid', '0'],
            'negative paid' => ['total_paid', '-1'],
            'negative refund' => ['total_refund', '-1'],
            'refund above paid' => ['total_paid', '999'],
            'currency drift' => ['currency', 'CAD'],
        ];
    }

    #[DataProvider('invalidOrderIdentity')]
    public function testRejectsInvalidOrderIdentityBeforeProviderWork(string $field, mixed $value): void
    {
        $order = $this->database->row('wp_fct_orders', 10);
        $order[$field] = $value;
        $this->database->replace('wp_fct_orders', $order);

        $this->assertAccountingDrift($this->loader()->load(20));
    }

    /** @return array<string,array{string,mixed}> */
    public static function invalidOrderIdentity(): array
    {
        return [
            'missing UUID' => ['uuid', ''],
            'blank UUID' => ['uuid', ' '],
            'UUID exceeds schema limit' => ['uuid', str_repeat('a', 101)],
            'UUID must be a string' => ['uuid', 123],
            'negative customer' => ['customer_id', -1],
            'negative string customer' => ['customer_id', '-1'],
            'fractional customer' => ['customer_id', '1.5'],
            'missing customer' => ['customer_id', null],
            'existing partial refund has paid status' => ['payment_status', 'paid'],
            'existing partial refund has blank status' => ['payment_status', ''],
        ];
    }

    public function testAcceptsCanonicalFullyRefundedPaymentStatus(): void
    {
        $order = $this->database->row('wp_fct_orders', 10);
        $order['total_paid'] = '1000';
        $order['payment_status'] = 'refunded';
        $this->database->replace('wp_fct_orders', $order);

        $result = $this->loader()->load(20);

        self::assertIsArray($result);
        self::assertSame(0, $result['remaining_refundable']);
    }

    public function testCapsOneChargeByTheRemainingOrderBalanceOnAMultiChargeOrder(): void
    {
        $source = $this->database->row('wp_fct_order_transactions', 20);
        $source['total'] = '4000';
        $source['meta'] = wp_json_encode(['refunded_total' => 0]);
        $this->database->replace('wp_fct_order_transactions', $source);

        $refund = $this->database->row('wp_fct_order_transactions', 21);
        $refund['total'] = '4500';
        $refund['meta'] = wp_json_encode(['parent_id' => 19]);
        $this->database->replace('wp_fct_order_transactions', $refund);

        $order = $this->database->row('wp_fct_orders', 10);
        $order['total_refund'] = '4500';
        $this->database->replace('wp_fct_orders', $order);

        $result = $this->loader()->load(20);

        self::assertIsArray($result);
        self::assertSame(4000, $result['transaction_total']);
        self::assertSame(0, $result['refunded_total']);
        self::assertSame(500, $result['remaining_refundable']);
    }

    public function testEffectiveMultiChargeCapFlowsThroughTheStrictRequestBoundary(): void
    {
        $source = $this->database->row('wp_fct_order_transactions', 20);
        $source['total'] = '4000';
        $source['meta'] = wp_json_encode(['refunded_total' => 0]);
        $this->database->replace('wp_fct_order_transactions', $source);
        $refund = $this->database->row('wp_fct_order_transactions', 21);
        $refund['total'] = '4500';
        $refund['meta'] = wp_json_encode(['parent_id' => 19]);
        $this->database->replace('wp_fct_order_transactions', $refund);
        $order = $this->database->row('wp_fct_orders', 10);
        $order['total_refund'] = '4500';
        $this->database->replace('wp_fct_orders', $order);
        $requestBuilder = new YSHelcimRefundRequest(
            $this->loader(),
            static fn (): array => ['current_mode' => 'test', 'api_token' => 'server-token'],
            static fn (): string => '203.0.113.9',
            static fn (): bool => true
        );

        $result = $requestBuilder->build(new RefundRestRequest([
            'operation_uuid' => '00000000-0000-4000-8000-000000000001',
            'transaction_id' => 20,
            'amount' => '5.00',
            'cancel_subscription' => false,
        ], ['order_id' => '10']), 7);

        self::assertIsArray($result);
        self::assertSame(500, $result['amount']);
        self::assertSame(500, $result['remaining_refundable']);
        self::assertSame(0, $result['refunded_total']);
    }

    public function testCapsByTheSourceBalanceWhenTheOrderHasMoreRemaining(): void
    {
        $order = $this->database->row('wp_fct_orders', 10);
        $order['total_paid'] = '10000';
        $this->database->replace('wp_fct_orders', $order);

        $result = $this->loader()->load(20);

        self::assertIsArray($result);
        self::assertSame(4000, $result['remaining_refundable']);
    }

    public function testMissingSourceOrOrderIsAccountingDriftRatherThanStorageFailure(): void
    {
        $this->assertAccountingDrift($this->loader()->load(999));

        $this->setUp();
        $source = $this->database->row('wp_fct_order_transactions', 20);
        $source['order_id'] = 999;
        $this->database->replace('wp_fct_order_transactions', $source);
        $this->assertAccountingDrift($this->loader()->load(20));
    }

    public function testOnlyRowsWithRefundedStatusContributeToAccountingSums(): void
    {
        $this->database->seed('wp_fct_order_transactions', [
            'id' => 22,
            'order_id' => 10,
            'transaction_type' => 'refund',
            'status' => 'failed',
            'total' => (string) PHP_INT_MAX,
            'meta' => '{not inspected for a non-refunded row',
        ]);

        $result = $this->loader()->load(20);

        self::assertIsArray($result);
        self::assertSame(1000, $result['refunded_total']);
        self::assertSame(4000, $result['remaining_refundable']);
    }

    #[DataProvider('invalidItems')]
    public function testRejectsMalformedOrderItemIdentity(string $field, mixed $value): void
    {
        $item = $this->database->row('wp_fct_order_items', 101);
        $item[$field] = $value;
        $this->database->replace('wp_fct_order_items', $item, 101);

        $this->assertAccountingDrift($this->loader()->load(20));
    }

    /** @return array<string,array{string,mixed}> */
    public static function invalidItems(): array
    {
        return [
            'missing item id' => ['id', 0],
            'negative object' => ['object_id', -1],
            'negative post' => ['post_id', -1],
            'zero quantity' => ['quantity', 0],
            'fractional quantity' => ['quantity', '1.5'],
            'negative line total' => ['line_total', '-1'],
            'fractional line total' => ['line_total', '1.5'],
            'negative refund total' => ['refund_total', '-1'],
            'refund exceeds line total' => ['refund_total', '3001'],
            'negative unit price' => ['unit_price', '-1'],
            'negative subtotal' => ['subtotal', '-1'],
            'negative tax amount' => ['tax_amount', '-1'],
            'negative shipping charge' => ['shipping_charge', '-1'],
            'negative discount total' => ['discount_total', '-1'],
        ];
    }

    public function testAcceptsAFluentCartFeeItemWithZeroPostAndNullableObject(): void
    {
        $fee = $this->database->row('wp_fct_order_items', 101);
        $fee['post_id'] = 0;
        $fee['object_id'] = null;
        $this->database->replace('wp_fct_order_items', $fee, 101);

        $result = $this->loader()->load(20);

        self::assertIsArray($result);
        self::assertSame(2, $result['order_item_quantities'][101]);
        self::assertSame(4000, $result['remaining_refundable']);
    }

    public function testRejectsAggregateItemRemainderOverflowBeforeProviderWork(): void
    {
        $first = $this->database->row('wp_fct_order_items', 101);
        $first['line_total'] = (string) PHP_INT_MAX;
        $first['refund_total'] = '0';
        $this->database->replace('wp_fct_order_items', $first);
        $second = $this->database->row('wp_fct_order_items', 102);
        $second['line_total'] = '1';
        $second['refund_total'] = '0';
        $this->database->replace('wp_fct_order_items', $second);

        $this->assertAccountingDrift($this->loader()->load(20));
    }

    public function testRejectsAnOrderWithoutItems(): void
    {
        $database = new RefundContextWpdb();
        $this->seedCanonicalState($database, false);
		$this->database = $database;

        $this->assertAccountingDrift((new YSHelcimRefundContextLoader($database))->load(20));
    }

    #[DataProvider('storageFailureTables')]
    public function testRollsBackAndReturns503OnAnyTableReadFailure(string $table): void
    {
        $this->database->failReadTable = $table;

        $result = $this->loader()->load(20);

        $this->assertStorageFailure($result);
        self::assertContains('ROLLBACK', $this->database->commands);
        self::assertFalse($this->database->inTransaction());
    }

    /** @return array<string,array{string}> */
    public static function storageFailureTables(): array
    {
        return [
            'source/refund table' => ['wp_fct_order_transactions'],
            'orders table' => ['wp_fct_orders'],
            'items table' => ['wp_fct_order_items'],
        ];
    }

    public function testRollsBackWhenTheSecondTransactionTableReadFails(): void
    {
        $this->database->failReadContaining = "transaction_type = 'refund'";

        $result = $this->loader()->load(20);

        $this->assertStorageFailure($result);
        self::assertCount(3, $this->database->reads, 'Source and order reads must succeed before the refund-row failure.');
        self::assertSame('ROLLBACK', end($this->database->commands));
    }

    public function testRollsBackAndReturns503WhenAReadThrows(): void
    {
        $this->database->throwReadTable = 'wp_fct_orders';

        $result = $this->loader()->load(20);

        $this->assertStorageFailure($result);
        self::assertSame('ROLLBACK', end($this->database->commands));
        self::assertFalse($this->database->inTransaction());
    }

    public function testCommitFailureIsRolledBackAndReturns503(): void
    {
        $this->database->failCommit = true;

        $result = $this->loader()->load(20);

        $this->assertStorageFailure($result);
        self::assertSame(['COMMIT', 'ROLLBACK'], array_slice($this->database->commands, -2));
        self::assertFalse($this->database->inTransaction());
    }

    public function testRollbackFailureEscalatesAccountingDriftTo503(): void
    {
        $source = $this->database->row('wp_fct_order_transactions', 20);
        $source['meta'] = '{broken';
        $this->database->replace('wp_fct_order_transactions', $source);
        $this->database->failRollback = true;

        $result = $this->loader()->load(20);

        $this->assertStorageFailure($result);
        self::assertSame('ROLLBACK', end($this->database->commands));
    }

    #[DataProvider('transactionStartFailures')]
    public function testTransactionSetupFailureReturns503AndAttemptsRollback(string $flag): void
    {
        $this->database->{$flag} = true;

        $result = $this->loader()->load(20);

        $this->assertStorageFailure($result);
        self::assertSame('ROLLBACK', end($this->database->commands));
        self::assertSame([], $this->database->reads);
    }

    /** @return array<string,array{string}> */
    public static function transactionStartFailures(): array
    {
        return [
            'isolation failure' => ['failIsolation'],
            'read-only start failure' => ['failStart'],
        ];
    }

    private function loader(): YSHelcimRefundContextLoader
    {
        return new YSHelcimRefundContextLoader($this->database);
    }

    private function assertAccountingDrift(mixed $result): void
    {
        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_accounting_drift', $result->get_error_code());
        self::assertSame(409, $result->get_error_data()['status']);
        self::assertSame('ROLLBACK', end($this->database->commands));
        self::assertFalse($this->database->inTransaction());
    }

    private function assertStorageFailure(mixed $result): void
    {
        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_refund_context_unavailable', $result->get_error_code());
        self::assertSame(503, $result->get_error_data()['status']);
    }

    private function seedCanonicalState(?RefundContextWpdb $database = null, bool $withItems = true): void
    {
        $database ??= $this->database;
        $database->createTable('wp_fct_order_transactions');
        $database->createTable('wp_fct_orders');
        $database->createTable('wp_fct_order_items');
        $database->seed('wp_fct_order_transactions', [
            'id' => 20,
            'order_id' => 10,
            'transaction_type' => 'charge',
            'vendor_charge_id' => '51177061',
            'payment_method' => 'ys_helcim',
            'payment_mode' => 'test',
            'currency' => 'USD',
            'order_type' => 'payment',
            'status' => 'succeeded',
            'total' => '5000',
            'meta' => wp_json_encode(['refunded_total' => 1000]),
            'uuid' => 'fc-transaction-123',
        ]);
        $database->seed('wp_fct_order_transactions', [
            'id' => 21,
            'order_id' => 10,
            'transaction_type' => 'refund',
            'status' => 'refunded',
            'total' => '1000',
            'meta' => wp_json_encode(['parent_id' => 20]),
        ]);
        $database->seed('wp_fct_orders', [
            'id' => 10,
            'type' => 'payment',
            'uuid' => 'fc-order-10',
            'customer_id' => '77',
            'currency' => 'USD',
            'total_paid' => '5000',
            'total_refund' => '1000',
            'payment_status' => 'partially_refunded',
        ]);
        if ($withItems) {
            $database->seed('wp_fct_order_items', [
                'id' => 101,
                'order_id' => 10,
                'post_id' => 301,
                'object_id' => 1001,
                'quantity' => 2,
                'unit_price' => '1500',
                'subtotal' => '3000',
                'tax_amount' => '0',
                'shipping_charge' => '0',
                'discount_total' => '0',
                'line_total' => '3000',
                'refund_total' => '600',
            ]);
            $database->seed('wp_fct_order_items', [
                'id' => 102,
                'order_id' => 10,
                'post_id' => 302,
                'object_id' => 1002,
                'quantity' => '1',
                'unit_price' => '2000',
                'subtotal' => '2000',
                'tax_amount' => '0',
                'shipping_charge' => '0',
                'discount_total' => '0',
                'line_total' => '2000',
                'refund_total' => '400',
            ]);
        }
    }
}
