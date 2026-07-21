<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Refund;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundOptionsLoader;

final class RefundOptionsLoaderTest extends TestCase
{
    public function testItProjectsOnlyServerOwnedRefundDisplayDataForAnEligibleHelcimOrder(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['api_token'] = 'top-level-secret';
        $snapshot['order']['customer_email'] = 'private@example.test';
        $snapshot['transactions'][0]['card_number'] = '4111111111111111';
        $snapshot['items'][0]['internal_meta'] = ['secret' => 'item-secret'];
        $loaded = [];
        $loader = new YSHelcimRefundOptionsLoader(
            static fn (int $orderId): array => $snapshot,
            function (int $transactionId) use (&$loaded): array {
                $loaded[] = $transactionId;
                return $this->context();
            }
        );

        $result = $loader->load(42);

        self::assertSame([7], $loaded);
        self::assertSame([
            'order_id' => 42,
            'classification' => 'helcim_only',
            'currency' => 'USD',
            'order_remaining' => 2100,
            'transactions' => [[
                'id' => 7,
                'gateway' => 'ys_helcim',
                'payment_mode' => 'test',
                'remaining_refundable' => 2100,
            ]],
            'items' => [[
                'id' => 9,
                'title' => 'Digital product',
                'quantity' => 2,
                'refundable_quantity' => 2,
            ]],
            'resolution_operation' => null,
        ], $result);
        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('top-level-secret', $encoded);
        self::assertStringNotContainsString('private@example.test', $encoded);
        self::assertStringNotContainsString('4111111111111111', $encoded);
        self::assertStringNotContainsString('item-secret', $encoded);
    }

    public function testItAcceptsCadAndBothHelcimGatewaysAndSortsTheSafeProjection(): void
    {
        $snapshot = $this->snapshot([
            'order' => [
                'id' => 42,
                'currency' => 'CAD',
                'total_paid' => 5000,
                'total_refund' => 1000,
            ],
            'transactions' => [
                $this->transaction(['id' => 8, 'payment_method' => 'ys_helcim_js']),
                $this->transaction(),
            ],
            'items' => [
                $this->item(['id' => 10, 'title' => 'Second']),
                $this->item(),
            ],
        ]);
        $contexts = [
            7 => $this->context(['currency' => 'CAD', 'remaining_refundable' => 1200]),
            8 => $this->context([
                'transaction_id' => 8,
                'vendor_transaction_id' => '51177062',
                'gateway' => 'ys_helcim_js',
                'currency' => 'CAD',
                'payment_mode' => 'live',
                'remaining_refundable' => 2800,
            ]),
        ];
        $loader = new YSHelcimRefundOptionsLoader(
            static fn (): array => $snapshot,
            static fn (int $transactionId): array => $contexts[$transactionId]
        );

        $result = $loader(42);

        self::assertSame('helcim_only', $result['classification']);
        self::assertSame('CAD', $result['currency']);
        self::assertSame(4000, $result['order_remaining']);
        self::assertSame([7, 8], array_column($result['transactions'], 'id'));
        self::assertSame([9, 10], array_column($result['items'], 'id'));
    }

    public function testItClassifiesMixedOnlyForAnotherRefundableSucceededNativeCharge(): void
    {
        $snapshot = $this->snapshot([
            'transactions' => [
                $this->transaction(),
                $this->transaction([
                    'id' => 20,
                    'payment_method' => 'stripe',
                    'remaining_refundable' => 500,
                ]),
                $this->transaction([
                    'id' => 21,
                    'payment_method' => 'paypal',
                    'status' => 'failed',
                    'remaining_refundable' => 500,
                ]),
                $this->transaction([
                    'id' => 22,
                    'payment_method' => 'manual',
                    'transaction_type' => 'refund',
                    'remaining_refundable' => 500,
                ]),
                $this->transaction([
                    'id' => 23,
                    'payment_method' => 'bank',
                    'remaining_refundable' => 0,
                ]),
            ],
        ]);
        $loader = $this->loader($snapshot, [7 => $this->context()]);

        $result = $loader->load(42);

        self::assertSame('mixed', $result['classification']);
        self::assertCount(1, $result['transactions']);
        self::assertSame('ys_helcim', $result['transactions'][0]['gateway']);
    }

    public function testUnknownNativeChargeAccountingKeepsTheNativeRefundUiAvailable(): void
    {
        $snapshot = $this->snapshot(['transactions' => [
            $this->transaction(),
            $this->transaction([
                'id' => 20,
                'payment_method' => 'stripe',
                'remaining_refundable' => null,
            ]),
        ]]);
        $loader = $this->loader($snapshot, [7 => $this->context()]);

        $result = $loader->load(42);

        self::assertSame('mixed', $result['classification']);
    }

    #[DataProvider('ineligibleContextProvider')]
    public function testItReturnsNoneWhenTheHelcimChargeIsNotEligible(array $contextOverrides): void
    {
        $loader = $this->loader(
            $this->snapshot(),
            [7 => $this->context($contextOverrides)]
        );

        $result = $loader->load(42);

        self::assertSame('none', $result['classification']);
        self::assertSame([], $result['transactions']);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function ineligibleContextProvider(): array
    {
        return [
            'not succeeded' => [['status' => 'pending']],
            'not a charge' => [['transaction_type' => 'refund']],
            'invalid provider id' => [['vendor_transaction_id' => 'txn_secret']],
            'overflow provider id' => [['vendor_transaction_id' => '999999999999999999999999999999']],
            'unsupported currency' => [['currency' => 'EUR']],
            'nothing remaining' => [['remaining_refundable' => 0]],
            'unsupported mode' => [['payment_mode' => 'sandbox']],
        ];
    }

    #[DataProvider('operationBlockerProvider')]
    public function testItBlocksEligibleHelcimRefundsForActiveOrManualOperations(array $operation): void
    {
        $snapshot = $this->snapshot(['operations' => [$operation]]);
        $loader = $this->loader($snapshot, [7 => $this->context()]);

        $result = $loader->load(42);

        self::assertSame('blocked', $result['classification']);
        self::assertCount(1, $result['transactions']);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function operationBlockerProvider(): array
    {
        return [
            'active refund scope' => [[
                'order_id' => 42,
                'operation_type' => 'refund',
                'active_scope_key' => str_repeat('a', 64),
                'remote_status' => 'processing',
                'local_status' => 'pending',
            ]],
            'manual reconciliation marker' => [[
                'order_id' => 42,
                'operation_type' => 'reverse',
                'active_scope_key' => null,
                'remote_status' => 'succeeded',
                'local_status' => 'failed',
                'manual_reconciliation_required' => true,
            ]],
            'database string manual marker' => [[
                'order_id' => 42,
                'operation_type' => 'refund',
                'active_scope_key' => null,
                'remote_status' => 'succeeded',
                'local_status' => 'applied',
                'manual_reconciliation_required' => '1',
            ]],
            'succeeded remotely but not locally applied' => [[
                'order_id' => 42,
                'operation_type' => 'refund',
                'active_scope_key' => null,
                'remote_status' => 'succeeded',
                'local_status' => 'recorded',
            ]],
            'indeterminate state even if the scope marker drifted' => [[
                'order_id' => 42,
                'operation_type' => 'refund',
                'active_scope_key' => null,
                'remote_status' => 'indeterminate',
                'local_status' => 'pending',
            ]],
            'durable stock reconciliation marker' => [[
                'order_id' => 42,
                'operation_type' => 'refund',
                'active_scope_key' => null,
                'remote_status' => 'succeeded',
                'local_status' => 'applied',
                'effect_status' => 'stock_reconciliation_required',
            ]],
        ];
    }

    public function testMalformedOrUnknownSameOrderOperationRowsFailClosedAsBlocked(): void
    {
        foreach ([
            ['order_id' => 42, 'operation_type' => 'refund'],
            [
                'order_id' => 42,
                'operation_type' => 'refund',
                'active_scope_key' => null,
                'remote_status' => 'future_state',
                'local_status' => 'applied',
            ],
            [
                'order_id' => 42,
                'operation_type' => 'refund',
                'active_scope_key' => [],
                'remote_status' => 'succeeded',
                'local_status' => 'applied',
            ],
            [
                'order_id' => 42,
                'operation_type' => 'refund',
                'active_scope_key' => null,
                'remote_status' => 'succeeded',
                'local_status' => 'applied',
                'manual_reconciliation_required' => 'maybe',
            ],
        ] as $operation) {
            $loader = $this->loader(
                $this->snapshot(['operations' => [$operation]]),
                [7 => $this->context()]
            );

            self::assertSame('blocked', $loader->load(42)['classification']);
        }
    }

    public function testItExposesOnlyOneExactActiveIndeterminateOperationForPositiveResolution(): void
    {
        $operationUuid = '11111111-2222-4333-8444-555555555555';
        $snapshot = $this->snapshot(['operations' => [[
            'operation_uuid' => $operationUuid,
            'order_id' => 42,
            'operation_type' => 'reverse',
            'active_scope_key' => 'yshs-' . str_repeat('a', 64),
            'remote_status' => 'indeterminate',
            'local_status' => 'failed',
            'manual_reconciliation_required' => '0',
            'effect_status' => '',
        ]]]);
        $loader = $this->loader($snapshot, [7 => $this->context()]);

        $result = $loader->load(42);

        self::assertSame('blocked', $result['classification']);
        self::assertSame([
            'operation_uuid' => $operationUuid,
            'provider_action' => 'reverse',
        ], $result['resolution_operation']);
    }

    public function testItNeverOffersResolutionForMalformedNonIndeterminateOrAmbiguousOperations(): void
    {
        $valid = [
            'operation_uuid' => '11111111-2222-4333-8444-555555555555',
            'order_id' => 42,
            'operation_type' => 'refund',
            'active_scope_key' => 'yshs-' . str_repeat('a', 64),
            'remote_status' => 'indeterminate',
            'local_status' => 'pending',
            'manual_reconciliation_required' => false,
            'effect_status' => '',
        ];

        foreach ([
            'malformed uuid' => [array_replace($valid, ['operation_uuid' => '../bad'])],
            'malformed active scope' => [array_replace($valid, ['active_scope_key' => str_repeat('a', 64)])],
            'processing' => [array_replace($valid, ['remote_status' => 'processing'])],
            'succeeded' => [array_replace($valid, [
                'active_scope_key' => null,
                'remote_status' => 'succeeded',
                'local_status' => 'applied',
            ])],
            'manual marker' => [array_replace($valid, ['manual_reconciliation_required' => true])],
            'ambiguous candidates' => [
                $valid,
                array_replace($valid, [
                    'operation_uuid' => '22222222-2222-4333-8444-555555555555',
                    'active_scope_key' => 'yshs-' . str_repeat('b', 64),
                ]),
            ],
            'another active blocker' => [
                $valid,
                array_replace($valid, [
                    'operation_uuid' => '22222222-2222-4333-8444-555555555555',
                    'active_scope_key' => 'yshs-' . str_repeat('b', 64),
                    'remote_status' => 'processing',
                ]),
            ],
        ] as $label => $operations) {
            $loader = $this->loader(
                $this->snapshot(['operations' => $operations]),
                [7 => $this->context()]
            );

            self::assertNull($loader->load(42)['resolution_operation'], $label);
        }
    }

    public function testResolvedOrUnrelatedOperationsDoNotBlock(): void
    {
        $snapshot = $this->snapshot(['operations' => [
            [
                'order_id' => 42,
                'operation_type' => 'refund',
                'active_scope_key' => null,
                'remote_status' => 'succeeded',
                'local_status' => 'applied',
            ],
            [
                'order_id' => 99,
                'operation_type' => 'refund',
                'active_scope_key' => 'other-order-active',
                'remote_status' => 'processing',
                'local_status' => 'pending',
            ],
            [
                'order_id' => 42,
                'operation_type' => 'purchase',
                'active_scope_key' => 'purchase-active',
                'remote_status' => 'processing',
                'local_status' => 'pending',
            ],
        ]]);
        $loader = $this->loader($snapshot, [7 => $this->context()]);

        $result = $loader->load(42);

        self::assertSame('helcim_only', $result['classification']);
    }

    public function testItDoesNotCallContextLoaderForRawTransactionsThatCannotBeEligible(): void
    {
        $snapshot = $this->snapshot(['transactions' => [
            $this->transaction(['status' => 'failed']),
            $this->transaction(['id' => 8, 'transaction_type' => 'refund']),
            $this->transaction(['id' => 9, 'payment_method' => 'stripe']),
        ]]);
        $calls = 0;
        $loader = new YSHelcimRefundOptionsLoader(
            static fn (): array => $snapshot,
            static function () use (&$calls): array {
                ++$calls;
                return [];
            }
        );

        $result = $loader->load(42);

        self::assertSame(0, $calls);
        self::assertSame('none', $result['classification']);
    }

    public function testQueryAndContextFailuresReturnSanitizedUnavailableErrors(): void
    {
        $queryFailure = new YSHelcimRefundOptionsLoader(
            static function (): array {
                throw new \RuntimeException('database password=secret');
            },
            static fn (): array => []
        );
        $contextFailure = new YSHelcimRefundOptionsLoader(
            static fn (): array => $this->snapshot(),
            static fn (): \WP_Error => new \WP_Error('unsafe', 'api token secret')
        );

        foreach ([$queryFailure->load(42), $contextFailure->load(42)] as $result) {
            self::assertTrue(is_wp_error($result));
            self::assertSame('ys_helcim_refund_options_unavailable', $result->get_error_code());
            self::assertSame(503, $result->get_error_data()['status']);
            self::assertStringNotContainsString('secret', $result->get_error_message());
        }
    }

    public function testInvalidOrMissingOrdersFailWithStablePublicErrors(): void
    {
        $queryCalls = 0;
        $loader = new YSHelcimRefundOptionsLoader(
            static function () use (&$queryCalls): null {
                ++$queryCalls;
                return null;
            },
            static fn (): array => []
        );

        $invalid = $loader->load(0);

        self::assertTrue(is_wp_error($invalid));
        self::assertSame('ys_helcim_invalid_order', $invalid->get_error_code());
        self::assertSame(422, $invalid->get_error_data()['status']);
        self::assertSame(0, $queryCalls);

        $missing = $loader->load(42);

        self::assertTrue(is_wp_error($missing));
        self::assertSame('ys_helcim_order_not_found', $missing->get_error_code());
        self::assertSame(404, $missing->get_error_data()['status']);
        self::assertSame(1, $queryCalls);
    }

    /** @param array<string, mixed> $overrides */
    private function snapshot(array $overrides = []): array
    {
        return array_replace([
            'order' => [
                'id' => 42,
                'currency' => 'USD',
                'total_paid' => 2100,
                'total_refund' => 0,
            ],
            'transactions' => [$this->transaction()],
            'items' => [$this->item()],
            'operations' => [],
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function transaction(array $overrides = []): array
    {
        return array_replace([
            'id' => 7,
            'order_id' => 42,
            'payment_method' => 'ys_helcim',
            'status' => 'succeeded',
            'transaction_type' => 'charge',
            'remaining_refundable' => 2100,
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function context(array $overrides = []): array
    {
        return array_replace([
            'order_id' => 42,
            'transaction_id' => 7,
            'vendor_transaction_id' => '51177061',
            'gateway' => 'ys_helcim',
            'status' => 'succeeded',
            'transaction_type' => 'charge',
            'remaining_refundable' => 2100,
            'currency' => 'USD',
            'payment_mode' => 'test',
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function item(array $overrides = []): array
    {
        return array_replace([
            'id' => 9,
            'title' => 'Digital product',
            'quantity' => 2,
            'refundable_quantity' => 2,
        ], $overrides);
    }

    /** @param array<int, array<string, mixed>> $contexts */
    private function loader(array $snapshot, array $contexts): YSHelcimRefundOptionsLoader
    {
        return new YSHelcimRefundOptionsLoader(
            static fn (): array => $snapshot,
            static fn (int $transactionId): array => $contexts[$transactionId]
        );
    }
}
