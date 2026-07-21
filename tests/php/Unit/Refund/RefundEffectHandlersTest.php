<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Refund;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundEffectHandlers;

final class RefundEffectHandlersTest extends TestCase
{
    private const OPERATION_UUID = '00000000-0000-4000-8000-000000000010';
    private const ROOT_UUID = '00000000-0000-4000-8000-000000000001';

    public function testHandlerRegistryUsesTheThreeDurableEffectNames(): void
    {
        $handlers = $this->handlers()->handlers();

        self::assertSame(['stock_restore', 'customer_recount', 'refund_hooks'], array_keys($handlers));
        foreach ($handlers as $handler) {
            self::assertIsCallable($handler);
        }
    }

    public function testStockRestoreUsesOnlyTheImmutableSnapshotAndMapsVariationIdentity(): void
    {
        $order = (object) ['id' => 10];
        $calls = [];
        $handlers = $this->handlers(
            orderLoader: static fn (int $id): object => $order,
            stockRestorer: static function (array $data) use (&$calls): bool {
                $calls[] = $data;
                return true;
            }
        );
        $payload = $this->basePayload() + [
            'manage_stock' => true,
            'items' => [[
                'item_id' => 101,
                'object_id' => 501,
                'post_id' => 301,
                'quantity' => 2,
                'restore_quantity' => 1,
            ]],
        ];

        $result = $handlers->stockRestore($payload, $this->effect('stock_restore', 'at_most_once', 10, $payload));

        self::assertSame(['status' => 'completed', 'effect' => 'stock_restore'], $result);
        self::assertCount(1, $calls);
        self::assertSame($order, $calls[0]['order']);
        self::assertTrue($calls[0]['manage_stock']);
        self::assertSame([], $calls[0]['refunded_items']);
        self::assertSame([[
            'id' => 101,
            'variation_id' => 501,
            'restore_quantity' => 1,
        ]], $calls[0]['new_refunded_items']);
    }

    public function testStockRestoreFalseIsANoOpWithoutLoadingFluentCart(): void
    {
        $called = false;
        $handlers = $this->handlers(
            orderLoader: static function () use (&$called): void {
                $called = true;
            },
            stockRestorer: static function () use (&$called): void {
                $called = true;
            }
        );
        $payload = $this->basePayload() + ['manage_stock' => false, 'items' => []];

        $result = $handlers->stockRestore($payload, $this->effect('stock_restore', 'at_most_once', 10, $payload));

        self::assertSame(['status' => 'skipped', 'effect' => 'stock_restore'], $result);
        self::assertFalse($called);
    }

    public function testCustomerRecountIsIdempotentAndUsesTheBoundCustomer(): void
    {
        $customer = (object) ['id' => 77];
        $calls = [];
        $handlers = $this->handlers(
            customerLoader: static fn (int $id): object => $customer,
            customerRecounter: static function (object $loaded) use (&$calls): bool {
                $calls[] = $loaded;
                return true;
            }
        );
        $payload = $this->basePayload() + ['customer_id' => 77];
        $effect = $this->effect('customer_recount', 'idempotent', 20, $payload);

        self::assertSame(
            ['status' => 'completed', 'effect' => 'customer_recount'],
            $handlers->customerRecount($payload, $effect)
        );
        self::assertSame(
            ['status' => 'completed', 'effect' => 'customer_recount'],
            $handlers->customerRecount($payload, $effect)
        );
        self::assertSame([$customer, $customer], $calls);
    }

    public function testHandlerAcceptsCanonicalMysqlIntegerStringForOutboxSequence(): void
    {
        $called = false;
        $payload = $this->basePayload() + ['customer_id' => 77];
        $effect = $this->effect('customer_recount', 'idempotent', 20, $payload);
        $effect['sequence'] = '20';
        $handlers = $this->handlers(customerRecounter: static function () use (&$called): bool {
            $called = true;
            return true;
        });

        $result = $handlers->customerRecount($payload, $effect);

        self::assertSame(['status' => 'completed', 'effect' => 'customer_recount'], $result);
        self::assertTrue($called);
    }

    public function testHandlerRejectsNoncanonicalDatabaseSequenceBeforeAnySideEffect(): void
    {
        $called = false;
        $payload = $this->basePayload() + ['customer_id' => 77];
        $effect = $this->effect('customer_recount', 'idempotent', 20, $payload);
        $effect['sequence'] = '020';
        $handlers = $this->handlers(customerRecounter: static function () use (&$called): bool {
            $called = true;
            return true;
        });

        $result = $handlers->customerRecount($payload, $effect);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_effect_payload_invalid', $result->get_error_code());
        self::assertFalse($called);
    }

    public function testCustomerZeroIsANoOpWithoutLoadingARecord(): void
    {
        $called = false;
        $handlers = $this->handlers(
            customerLoader: static function () use (&$called): void {
                $called = true;
            },
            customerRecounter: static function () use (&$called): void {
                $called = true;
            }
        );
        $payload = $this->basePayload() + ['customer_id' => 0];

        $result = $handlers->customerRecount(
            $payload,
            $this->effect('customer_recount', 'idempotent', 20, $payload)
        );

        self::assertSame(['status' => 'skipped', 'effect' => 'customer_recount'], $result);
        self::assertFalse($called);
    }

    public function testRefundHooksBuildTheFluentCartPayloadFromImmutableSnapshotsWithStockDisabled(): void
    {
        $order = (object) ['id' => 10, 'uuid' => 'fc-order-10', 'payment_status' => 'partially_refunded'];
        $transaction = $this->localRefundTransaction();
        $customer = (object) ['id' => 77];
        $actions = [];
        $activity = [];
        $sequence = [];
        $handlers = $this->handlers(
            orderLoader: static fn (int $id): object => $order,
            transactionLoader: static fn (int $id): object => $transaction,
            customerLoader: static fn (int $id): object => $customer,
            actionDispatcher: static function (string $hook, array $data) use (&$actions, &$sequence): void {
                $sequence[] = $hook;
                $actions[] = [$hook, $data];
            },
            activityLogger: static function (string $title, string $content, string $status, array $context) use (&$activity, &$sequence): void {
                $sequence[] = 'activity';
                $activity[] = [$title, $content, $status, $context];
            }
        );
        $payload = $this->hooksPayload();

        $result = $handlers->refundHooks($payload, $this->effect('refund_hooks', 'at_most_once', 30, $payload));

        self::assertSame(['status' => 'completed', 'effect' => 'refund_hooks'], $result);
        self::assertSame(['activity', 'fluent_cart/order_refunded', 'fluent_cart/order_partially_refunded'], $sequence);
        self::assertSame([[
            'Order Refund',
            'Order Refund successfully!',
            'success',
            [
                'module_type' => \stdClass::class,
                'module_id' => 10,
                'module_name' => 'Order',
                'user_id' => 42,
                'created_by' => 'Admin User',
            ],
        ]], $activity);
        self::assertSame(['fluent_cart/order_refunded', 'fluent_cart/order_partially_refunded'], array_column($actions, 0));
        self::assertCount(2, $actions);
        $event = $actions[0][1];
        self::assertSame([
            'order',
            'refunded_items',
            'new_refunded_items',
            'refunded_amount',
            'manage_stock',
            'transaction',
            'customer',
            'type',
        ], array_keys($event));
        self::assertSame($order, $event['order']);
        self::assertSame($transaction, $event['transaction']);
        self::assertSame($customer, $event['customer']);
        self::assertSame($payload['refunded_item_snapshots'], $event['refunded_items']);
        self::assertSame($payload['refunded_items'], $event['new_refunded_items']);
        self::assertSame(2100, $event['refunded_amount']);
        self::assertFalse($event['manage_stock']);
        self::assertSame('partial', $event['type']);
        self::assertSame($event, $actions[1][1]);
    }

    public function testFullRefundUsesTheFullRefundHook(): void
    {
        $actions = [];
        $handlers = $this->handlers(
            orderLoader: static fn (int $id): object => (object) [
                'id' => $id,
                'uuid' => 'fc-order-10',
                'payment_status' => 'refunded',
            ],
            actionDispatcher: static function (string $hook) use (&$actions): void {
                $actions[] = $hook;
            }
        );
        $payload = $this->hooksPayload();
        $payload['refund_type'] = 'full';

        $result = $handlers->refundHooks($payload, $this->effect('refund_hooks', 'at_most_once', 30, $payload));

        self::assertIsArray($result);
        self::assertSame(['fluent_cart/order_refunded', 'fluent_cart/order_fully_refunded'], $actions);
    }

    public function testHooksWithoutCustomerUseNullAndNeverAttemptCustomerLoad(): void
    {
        $customerLoaded = false;
        $event = null;
        $handlers = $this->handlers(
            customerLoader: static function () use (&$customerLoaded): void {
                $customerLoaded = true;
            },
            actionDispatcher: static function (string $hook, array $data) use (&$event): void {
                if ($hook === 'fluent_cart/order_refunded') {
                    $event = $data;
                }
            }
        );
        $payload = $this->hooksPayload();
        $payload['customer_id'] = 0;

        $result = $handlers->refundHooks($payload, $this->effect('refund_hooks', 'at_most_once', 30, $payload));

        self::assertIsArray($result);
        self::assertFalse($customerLoaded);
        self::assertIsArray($event);
        self::assertNull($event['customer']);
    }

    public function testActivityFailureStopsPublicHooksAndDoesNotLeakTheException(): void
    {
        $hooksCalled = false;
        $payload = $this->hooksPayload();
        $handlers = $this->handlers(
            actionDispatcher: static function () use (&$hooksCalled): void {
                $hooksCalled = true;
            },
            activityLogger: static function (): never {
                throw new \RuntimeException('activity-database-password');
            }
        );

        $result = $handlers->refundHooks($payload, $this->effect('refund_hooks', 'at_most_once', 30, $payload));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_effect_dependency_failed', $result->get_error_code());
        self::assertStringNotContainsString('password', $result->get_error_message());
        self::assertFalse($hooksCalled);
    }

    public function testMissingPersistedActorFallsBackToBotWithoutUsingTheWorkerIdentity(): void
    {
        $context = null;
        $payload = $this->hooksPayload();
        $handlers = $this->handlers(
            actorLoader: static fn (int $id): null => null,
            activityLogger: static function (string $title, string $content, string $status, array $activityContext) use (&$context): void {
                $context = $activityContext;
            }
        );

        $result = $handlers->refundHooks($payload, $this->effect('refund_hooks', 'at_most_once', 30, $payload));

        self::assertIsArray($result);
        self::assertIsArray($context);
        self::assertSame(42, $context['user_id']);
        self::assertSame('FCT-BOT', $context['created_by']);
    }

    public function testPayloadMustStrictlyMatchThePersistedJsonAndHash(): void
    {
        $payload = $this->basePayload() + ['customer_id' => 77];
        $effect = $this->effect('customer_recount', 'idempotent', 20, $payload);
        $tampered = $payload;
        $tampered['order_id'] = 11;

        $result = $this->handlers()->customerRecount($tampered, $effect);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_effect_payload_invalid', $result->get_error_code());
    }

    public function testMalformedPersistedJsonFailsClosed(): void
    {
        $called = false;
        $payload = $this->basePayload() + ['customer_id' => 77];
        $effect = $this->effect('customer_recount', 'idempotent', 20, $payload);
        $effect['payload'] = '{bad-json';
        $effect['payload_hash'] = hash('sha256', '{bad-json');
        $handlers = $this->handlers(customerLoader: static function () use (&$called): void {
            $called = true;
        });

        $result = $handlers->customerRecount([], $effect);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_effect_payload_invalid', $result->get_error_code());
        self::assertFalse($called);
    }

    public function testHashMismatchFailsBeforeAnyDependencyIsCalled(): void
    {
        $called = false;
        $payload = $this->basePayload() + ['customer_id' => 77];
        $effect = $this->effect('customer_recount', 'idempotent', 20, $payload);
        $effect['payload_hash'] = str_repeat('0', 64);
        $handlers = $this->handlers(customerLoader: static function () use (&$called): void {
            $called = true;
        });

        $result = $handlers->customerRecount($payload, $effect);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_effect_payload_invalid', $result->get_error_code());
        self::assertFalse($called);
    }

    public function testWrongOutboxIdentityFailsClosedBeforeAnySideEffect(): void
    {
        $called = false;
        $payload = $this->basePayload() + ['customer_id' => 77];
        $effect = $this->effect('customer_recount', 'at_most_once', 20, $payload);
        $handlers = $this->handlers(customerRecounter: static function () use (&$called): void {
            $called = true;
        });

        $result = $handlers->customerRecount($payload, $effect);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_effect_payload_invalid', $result->get_error_code());
        self::assertFalse($called);
    }

    #[DataProvider('invalidCommonIdentityProvider')]
    public function testCommonIdentityMustFailClosed(string $field, mixed $value): void
    {
        $payload = $this->basePayload() + ['customer_id' => 77];
        $payload[$field] = $value;

        $result = $this->handlers()->customerRecount(
            $payload,
            $this->effect('customer_recount', 'idempotent', 20, $payload)
        );

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_effect_payload_invalid', $result->get_error_code());
    }

    /** @return array<string,array{string,mixed}> */
    public static function invalidCommonIdentityProvider(): array
    {
        return [
            'unsupported version' => ['version', 2],
            'invalid operation UUID' => ['operation_uuid', 'not-a-uuid'],
            'invalid order id' => ['order_id', 0],
            'invalid local transaction id' => ['local_transaction_id', '30'],
        ];
    }

    #[DataProvider('invalidHookPayloadProvider')]
    public function testHookFinancialIdentityAndTypeMustFailClosed(string $field, mixed $value): void
    {
        $called = false;
        $payload = $this->hooksPayload();
        $payload[$field] = $value;
        $handlers = $this->handlers(actionDispatcher: static function () use (&$called): void {
            $called = true;
        });

        $result = $handlers->refundHooks($payload, $this->effect('refund_hooks', 'at_most_once', 30, $payload));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_effect_payload_invalid', $result->get_error_code());
        self::assertFalse($called);
    }

    /** @return array<string,array{string,mixed}> */
    public static function invalidHookPayloadProvider(): array
    {
        return [
            'root refund UUID' => ['root_refund_uuid', 'bad'],
            'order UUID' => ['order_uuid', ''],
            'source transaction' => ['source_transaction_id', 0],
            'provider transaction' => ['provider_transaction_id', '0'],
            'provider action' => ['provider_action', 'void'],
            'refund amount' => ['refund_amount', 0],
            'currency' => ['currency', 'EUR'],
            'refund type' => ['refund_type', 'complete'],
            'stock flag is always false' => ['manage_stock', true],
            'stock request flag is boolean' => ['stock_restore_requested', 1],
            'actor user id is an integer' => ['actor_user_id', '42'],
        ];
    }

    public function testSnapshotOrderAndIdsMustMatchTheHookIdentity(): void
    {
        $payload = $this->hooksPayload();
        $payload['refunded_item_snapshots'][0]['order_id'] = 11;

        $result = $this->handlers()->refundHooks($payload, $this->effect('refund_hooks', 'at_most_once', 30, $payload));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_effect_payload_invalid', $result->get_error_code());
    }

    public function testHookPayloadAcceptsActualFluentCartColumnLimits(): void
    {
        $payload = $this->hooksPayload();
        $payload['order_uuid'] = str_repeat('a', 100);
        $payload['refunded_item_snapshots'][0]['post_title'] = str_repeat('P', 600);
        $payload['refunded_item_snapshots'][0]['title'] = str_repeat('V', 600);
        $handlers = $this->handlers(orderLoader: static fn (int $id): object => (object) [
            'id' => $id,
            'uuid' => str_repeat('a', 100),
            'payment_status' => 'partially_refunded',
        ]);

        $result = $handlers->refundHooks($payload, $this->effect('refund_hooks', 'at_most_once', 30, $payload));

        self::assertSame(['status' => 'completed', 'effect' => 'refund_hooks'], $result);
    }

    public function testHookPayloadAcceptsAFluentCartFeeSnapshotWithoutProductIds(): void
    {
        $payload = $this->hooksPayload();
        $payload['stock_restore_requested'] = false;
        $payload['refunded_items'] = [];
        $payload['refunded_item_snapshots'][0]['post_id'] = 0;
        $payload['refunded_item_snapshots'][0]['object_id'] = null;
        $payload['refunded_item_snapshots'][0]['fulfillment_type'] = 'fee';

        $result = $this->handlers()->refundHooks(
            $payload,
            $this->effect('refund_hooks', 'at_most_once', 30, $payload)
        );

        self::assertSame(['status' => 'completed', 'effect' => 'refund_hooks'], $result);
    }

    public function testLoaderIdentityMismatchFailsClosed(): void
    {
        $called = false;
        $payload = $this->hooksPayload();
        $handlers = $this->handlers(
            orderLoader: static fn (): object => (object) ['id' => 11, 'uuid' => 'fc-order-10'],
            actionDispatcher: static function () use (&$called): void {
                $called = true;
            }
        );

        $result = $handlers->refundHooks($payload, $this->effect('refund_hooks', 'at_most_once', 30, $payload));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_effect_record_mismatch', $result->get_error_code());
        self::assertFalse($called);
    }

    public function testDependencyErrorsAndExceptionsNeverLeakProviderSecrets(): void
    {
        $payload = $this->hooksPayload();
        $handlers = $this->handlers(orderLoader: static function (): never {
            throw new \RuntimeException('api-token-supersecret');
        });

        $result = $handlers->refundHooks($payload, $this->effect('refund_hooks', 'at_most_once', 30, $payload));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_effect_dependency_failed', $result->get_error_code());
        self::assertStringNotContainsString('supersecret', $result->get_error_message());
        self::assertSame(['retryable' => true], $result->get_error_data());
    }

    public function testMissingProductionFluentCartModelsReturnANonRetryableGenericError(): void
    {
        $payload = $this->hooksPayload();

        $result = (new YSHelcimRefundEffectHandlers())->refundHooks(
            $payload,
            $this->effect('refund_hooks', 'at_most_once', 30, $payload)
        );

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_effect_dependency_missing', $result->get_error_code());
        self::assertSame(['retryable' => false], $result->get_error_data());
    }

    private function handlers(
        ?callable $orderLoader = null,
        ?callable $transactionLoader = null,
        ?callable $customerLoader = null,
        ?callable $stockRestorer = null,
        ?callable $customerRecounter = null,
        ?callable $actionDispatcher = null,
        ?callable $activityLogger = null,
        ?callable $actorLoader = null
    ): YSHelcimRefundEffectHandlers {
        return new YSHelcimRefundEffectHandlers(
            $orderLoader ?? static fn (int $id): object => (object) [
                'id' => $id,
                'uuid' => 'fc-order-10',
                'payment_status' => 'partially_refunded',
            ],
            $transactionLoader ?? fn (int $id): object => $this->localRefundTransaction($id),
            $customerLoader ?? static fn (int $id): object => (object) ['id' => $id],
            $stockRestorer ?? static fn (): bool => true,
            $customerRecounter ?? static fn (): bool => true,
            $actionDispatcher ?? static fn (): bool => true,
            $activityLogger ?? static fn (): bool => true,
            $actorLoader ?? static fn (int $id): object => (object) ['ID' => $id, 'display_name' => 'Admin User']
        );
    }

    /** @return array<string,mixed> */
    private function basePayload(): array
    {
        return [
            'version' => 1,
            'operation_uuid' => self::OPERATION_UUID,
            'order_id' => 10,
            'local_transaction_id' => 30,
        ];
    }

    private function localRefundTransaction(int $id = 30): object
    {
        return (object) [
            'id' => $id,
            'order_id' => 10,
            'transaction_type' => 'refund',
            'status' => 'refunded',
            'total' => 2100,
            'currency' => 'USD',
            'uuid' => self::OPERATION_UUID,
            'vendor_charge_id' => '51177123',
        ];
    }

    /** @return array<string,mixed> */
    private function hooksPayload(): array
    {
        return $this->basePayload() + [
            'root_refund_uuid' => self::ROOT_UUID,
            'order_uuid' => 'fc-order-10',
            'customer_id' => 77,
            'source_transaction_id' => 20,
            'provider_transaction_id' => '51177123',
            'provider_action' => 'refund',
            'refund_amount' => 2100,
            'currency' => 'USD',
            'refund_type' => 'partial',
            'reason' => 'Customer requested refund',
            'item_ids' => [101],
            'manage_stock' => false,
            'stock_restore_requested' => true,
            'actor_user_id' => 42,
            'refunded_items' => [[
                'id' => 101,
                'restore_quantity' => 1,
            ]],
            'refunded_item_snapshots' => [[
                'id' => 101,
                'order_id' => 10,
                'post_id' => 301,
                'object_id' => 501,
                'fulfillment_type' => 'physical',
                'payment_type' => 'onetime',
                'post_title' => 'Product',
                'title' => 'Blue',
                'quantity' => 2,
                'unit_price' => 1500,
                'subtotal' => 3000,
                'tax_amount' => 0,
                'shipping_charge' => 0,
                'discount_total' => 0,
                'line_total' => 3000,
                'refund_total' => 600,
                'rate' => '1.0000',
                'fulfilled_quantity' => 0,
            ]],
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function effect(string $type, string $class, int $sequence, array $payload): array
    {
        $json = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        self::assertIsString($json);

        return [
            'id' => 1,
            'operation_uuid' => self::OPERATION_UUID,
            'effect_type' => $type,
            'effect_class' => $class,
            'sequence' => $sequence,
            'payload' => $json,
            'payload_hash' => hash('sha256', $json),
            'status' => 'processing',
            'claim_token' => '00000000-0000-4000-8000-000000000099',
        ];
    }
}
