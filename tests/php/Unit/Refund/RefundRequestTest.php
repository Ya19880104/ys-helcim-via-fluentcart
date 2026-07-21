<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Refund;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundRequest;
use YangSheep\Helcim\FluentCart\Tests\Doubles\RefundRestRequest;

final class RefundRequestTest extends TestCase
{
    public function testBuildUsesStrictClientFieldsAndServerDerivedPaymentContext(): void
    {
        $loadedIds = [];
        $credentials = [];
        $builder = $this->builder(
            static function (int $transactionId) use (&$loadedIds): array {
                $loadedIds[] = $transactionId;
                return self::transaction();
            },
            static function (string $gateway, string $mode) use (&$credentials): array {
                $credentials[] = [$gateway, $mode];
                return ['current_mode' => 'test', 'api_token' => 'server-only-token'];
            }
        );
        $request = new RefundRestRequest(
            $this->body([
                'reason' => '<b>Customer request</b>',
                'item_ids' => [102, 101],
                'manage_stock' => true,
                'refunded_items' => [
                    ['id' => 102, 'restore_quantity' => 1],
                    ['id' => 101, 'restore_quantity' => 2],
                ],
                'cancel_subscription' => false,
            ]),
            ['order_id' => '10']
        );

        $result = $builder->build($request, 7);

        self::assertIsArray($result);
        self::assertSame([20], $loadedIds);
        self::assertSame([['ys_helcim', 'test']], $credentials);
        self::assertSame(2100, $result['amount']);
        self::assertSame(10, $result['order_id']);
        self::assertSame(20, $result['transaction_id']);
        self::assertSame('fc-transaction-123', $result['transaction_uuid']);
        self::assertSame('51177061', $result['vendor_transaction_id']);
        self::assertSame(2100, $result['transaction_total']);
        self::assertSame(0, $result['refunded_total']);
        self::assertSame(2100, $result['remaining_refundable']);
        self::assertSame('USD', $result['currency']);
        self::assertSame('test', $result['payment_mode']);
        self::assertSame('test', $result['current_mode']);
        self::assertSame('server-only-token', $result['api_token']);
        self::assertSame('203.0.113.9', $result['ip_address']);
        self::assertSame([
            'version' => 1,
            'reason' => 'Customer request',
            'item_ids' => [101, 102],
            'manage_stock' => true,
            'refunded_items' => [
                ['id' => 101, 'restore_quantity' => 2],
                ['id' => 102, 'restore_quantity' => 1],
            ],
            'cancel_subscription' => false,
            'actor_user_id' => 7,
        ], $result['local_payload']);
    }

    #[DataProvider('invalidAmounts')]
    public function testBuildRejectsNonCanonicalOrOverflowingDecimalAmounts(mixed $amount): void
    {
        $result = $this->builder()->build(
            new RefundRestRequest($this->body(['amount' => $amount]), ['order_id' => '10']),
            7
        );

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_invalid_refund_request', $result->get_error_code());
    }

    /** @return array<string, array{mixed}> */
    public static function invalidAmounts(): array
    {
        return [
            'integer' => [21],
            'float' => [21.0],
            'missing fraction' => ['21'],
            'one decimal place' => ['21.0'],
            'three decimal places' => ['21.000'],
            'leading zero' => ['021.00'],
            'scientific notation' => ['2.1e1'],
            'plus sign' => ['+21.00'],
            'whitespace' => [' 21.00'],
            'zero' => ['0.00'],
            'overflow' => [(string) PHP_INT_MAX . '.00'],
        ];
    }

    #[DataProvider('immutableOverrides')]
    public function testBuildRejectsClientControlledImmutableContext(string $field, mixed $value): void
    {
        $result = $this->builder()->build(
            new RefundRestRequest($this->body([$field => $value]), ['order_id' => '10']),
            7
        );

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_invalid_refund_request', $result->get_error_code());
    }

    /** @return array<string, array{string, mixed}> */
    public static function immutableOverrides(): array
    {
        return [
            'body order id' => ['order_id', 10],
            'gateway' => ['gateway', 'ys_helcim'],
            'currency' => ['currency', 'USD'],
            'vendor id' => ['vendor_transaction_id', '51177061'],
            'transaction total' => ['transaction_total', 2100],
            'refunded total' => ['refunded_total', 0],
            'remaining amount' => ['remaining_refundable', 2100],
            'payment mode' => ['payment_mode', 'test'],
            'current mode' => ['current_mode', 'test'],
            'API token' => ['api_token', 'client-token'],
            'IP address' => ['ip_address', '127.0.0.1'],
            'actor' => ['actor_user_id', 7],
            'pre-normalized payload' => ['local_payload', []],
            'unknown field' => ['unexpected', true],
        ];
    }

    #[DataProvider('invalidTransactions')]
    public function testBuildRejectsStaleWrongOrderOrNonHelcimTransactions(array $overrides): void
    {
        $builder = $this->builder(
            static fn (): array => array_merge(self::transaction(), $overrides)
        );

        $result = $builder->build(
            new RefundRestRequest($this->body(), ['order_id' => '10']),
            7
        );

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_invalid_refund_request', $result->get_error_code());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidTransactions(): array
    {
        return [
            'wrong order' => [['order_id' => 11]],
            'wrong transaction identity' => [['transaction_id' => 21]],
            'non Helcim gateway' => [['gateway' => 'stripe']],
            'pending charge' => [['status' => 'pending']],
            'refund row' => [['transaction_type' => 'refund']],
            'bad provider id' => [['vendor_transaction_id' => 'legacy-1']],
            'unsupported currency' => [['currency' => 'EUR']],
            'invalid stored mode' => [['payment_mode' => 'sandbox']],
            'negative refunded total' => [['refunded_total' => -1]],
            'stale refundable arithmetic' => [['remaining_refundable' => 2000]],
        ];
    }

    #[DataProvider('invalidItemSelections')]
    public function testBuildRejectsItemsOutsideTheOrderOrExcessRestoreQuantity(array $overrides): void
    {
        $result = $this->builder()->build(
            new RefundRestRequest($this->body($overrides), ['order_id' => '10']),
            7
        );

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_invalid_refund_request', $result->get_error_code());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidItemSelections(): array
    {
        return [
            'foreign item id' => [['item_ids' => [999]]],
            'foreign restore row' => [[
                'item_ids' => [999],
                'manage_stock' => true,
                'refunded_items' => [['id' => 999, 'restore_quantity' => 1]],
            ]],
            'restore above purchased quantity' => [[
                'item_ids' => [101],
                'manage_stock' => true,
                'refunded_items' => [['id' => 101, 'restore_quantity' => 3]],
            ]],
            'subscription cancellation' => [['cancel_subscription' => true]],
        ];
    }

    public function testBuildMapsUnavailableServerContextToServiceError(): void
    {
        $builder = $this->builder(
            static function (): never {
                throw new \RuntimeException('database unavailable');
            }
        );

        $result = $builder->build(
            new RefundRestRequest($this->body(), ['order_id' => '10']),
            7
        );

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_refund_context_unavailable', $result->get_error_code());
    }

    public function testStoragePreflightStopsBeforeLoadingOrSendingPaymentContext(): void
    {
        $loads = 0;
        $builder = $this->builder(
            loader: static function () use (&$loads): array {
                ++$loads;
                return self::transaction();
            },
            preflight: static fn (): \WP_Error => new \WP_Error(
                'ys_helcim_storage_not_transactional',
                'Unsafe storage.',
                ['status' => 503]
            )
        );

        $result = $builder->build(
            new RefundRestRequest($this->body(), ['order_id' => '10']),
            7
        );

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_storage_not_transactional', $result->get_error_code());
        self::assertSame(0, $loads);
    }

    public function testMissingStoragePreflightFailsClosedBeforeContextLoad(): void
    {
        $loads = 0;
        $builder = new YSHelcimRefundRequest(
            static function () use (&$loads): array {
                ++$loads;
                return self::transaction();
            },
            static fn (): array => ['current_mode' => 'test', 'api_token' => 'server-only-token'],
            static fn (): string => '203.0.113.9'
        );

        $result = $builder->build(
            new RefundRestRequest($this->body(), ['order_id' => '10']),
            7
        );

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_refund_context_unavailable', $result->get_error_code());
        self::assertSame(0, $loads);
    }

    public function testOrderLevelRemainingCapMayBeLowerThanSourceBalance(): void
    {
        $transaction = self::transaction();
        $transaction['remaining_refundable'] = 1000;
        $builder = $this->builder(loader: static fn (): array => $transaction);

        $result = $builder->build(
            new RefundRestRequest($this->body(['amount' => '10.00']), ['order_id' => '10']),
            7
        );

        self::assertIsArray($result);
        self::assertSame(1000, $result['remaining_refundable']);
    }

    public function testBuildRejectsPaymentModeCredentialDriftAsConflictBeforeServiceExecution(): void
    {
        $builder = $this->builder(
            credentials: static fn (): array => [
                'current_mode' => 'live',
                'api_token' => 'different-account-token',
            ]
        );

        $result = $builder->build(
            new RefundRestRequest($this->body(), ['order_id' => '10']),
            7
        );

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_account_drift', $result->get_error_code());
        self::assertSame(409, $result->get_error_data()['status']);
    }

    private function builder(?callable $loader = null, ?callable $credentials = null, ?callable $preflight = null): YSHelcimRefundRequest
    {
        return new YSHelcimRefundRequest(
            $loader ?? static fn (): array => self::transaction(),
            $credentials ?? static fn (): array => [
                'current_mode' => 'test',
                'api_token' => 'server-only-token',
            ],
            static fn (): string => '203.0.113.9',
            $preflight ?? static fn (): bool => true
        );
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function body(array $overrides = []): array
    {
        return array_merge([
            'operation_uuid' => '00000000-0000-4000-8000-000000000001',
            'transaction_id' => 20,
            'amount' => '21.00',
            'reason' => '',
            'item_ids' => [],
            'manage_stock' => false,
            'refunded_items' => [],
            'cancel_subscription' => false,
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private static function transaction(): array
    {
        return [
            'order_id' => 10,
            'transaction_id' => 20,
            'transaction_uuid' => 'fc-transaction-123',
            'vendor_transaction_id' => '51177061',
            'gateway' => 'ys_helcim',
            'status' => 'succeeded',
            'transaction_type' => 'charge',
            'transaction_total' => 2100,
            'refunded_total' => 0,
            'remaining_refundable' => 2100,
            'currency' => 'USD',
            'payment_mode' => 'test',
            'order_item_quantities' => [101 => 2, 102 => 1],
        ];
    }
}
