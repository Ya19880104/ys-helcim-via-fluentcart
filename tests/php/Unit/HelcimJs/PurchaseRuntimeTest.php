<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\HelcimJs;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use YangSheep\Helcim\FluentCart\HelcimJs\YSHelcimJsPurchaseRuntime;
use YangSheep\Helcim\FluentCart\HelcimJs\YSHelcimJsSettings;
use YangSheep\Helcim\FluentCart\HelcimPay\YSHelcimPaySettings;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimPurchaseOperation;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationRepository;
use YangSheep\Helcim\FluentCart\Tests\Doubles\FakeWpdb;

#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
final class PurchaseRuntimeTest extends TestCase
{
    private const OPERATION_UUID = '00000000-0000-4000-8000-000000000777';

    private FakeWpdb $database;
    private YSHelcimOperationRepository $repository;
    private YSHelcimJsSettings $settings;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/Doubles/InlineFluentCart.php';
        require_once dirname(__DIR__, 2) . '/Doubles/InlineWordPress.php';
        OrderTransaction::reset();
        Order::reset();
        StatusHelper::reset();
        StoreSettings::$orderMode = 'test';
        BaseGatewaySettings::$settingsByClass[YSHelcimJsSettings::class] = [
            'test_api_token' => 'enc:test-api-secret',
            'live_api_token' => 'enc:live-api-secret',
            'test_js_secret_key' => 'enc:js-secret',
        ];
        $this->settings = new YSHelcimJsSettings();
        $this->database = new FakeWpdb();
        $this->repository = new YSHelcimOperationRepository(
            $this->database,
            static fn (): string => '2026-07-21 00:00:00'
        );
        $this->seedServerModels();
    }

    public function testProductionPurchaseUsesFourArgumentCorrelationAndCanonicalNestedInvoice(): void
    {
        $apiCalls = [];
        $runtime = $this->runtime(function (...$args) use (&$apiCalls): array {
            $apiCalls[] = $args;
            return self::approved();
        });

        $result = $runtime->executeInline($this->transaction(), 'ephemeral-card-token');

        self::assertSame('succeeded', $result['status']);
        self::assertSame(self::OPERATION_UUID, $result['operation_uuid']);
        self::assertCount(1, $apiCalls);
        [$endpoint, $payload, $apiToken, $idempotencyKey, $method] = $apiCalls[0];
        self::assertSame('payment/purchase', $endpoint);
        self::assertSame('POST', $method);
        self::assertSame('test-api-secret', $apiToken);
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{25,36}\z/', $idempotencyKey);
        self::assertArrayNotHasKey('invoiceNumber', $payload);
        self::assertSame(self::OPERATION_UUID, $payload['invoice']['invoiceNumber']);
        self::assertSame([[
            'sku' => 'YSFC-20',
            'description' => 'FluentCart order 10',
            'quantity' => 1,
            'price' => '21.00',
        ]], $payload['invoice']['lineItems']);
        self::assertSame('21.00', $payload['amount']);
        self::assertSame('USD', $payload['currency']);
        self::assertSame('ephemeral-card-token', $payload['cardData']['cardToken']);

        $storedTransaction = OrderTransaction::allRecords()[20];
        self::assertSame(Status::TRANSACTION_SUCCEEDED, $storedTransaction['status']);
        self::assertSame('51177123', $storedTransaction['vendor_charge_id']);
        self::assertArrayNotHasKey('card_token', $storedTransaction['meta']);
        self::assertStringNotContainsString(
            'ephemeral-card-token',
            json_encode($this->database->allRows(), JSON_THROW_ON_ERROR)
        );
        self::assertCount(1, StatusHelper::$syncs);
    }

    public function testCanonicalFieldsAreRestoredAfterPayloadFilterAndTopLevelInvoiceIsRemoved(): void
    {
        $captured = null;
        $runtime = $this->runtime(
            static function (string $endpoint, array $payload) use (&$captured): array {
                unset($endpoint);
                $captured = $payload;
                return self::approved();
            },
            payloadFilter: static fn (): array => [
                'amount' => '0.01',
                'currency' => 'CAD',
                'cardData' => ['cardToken' => 'attacker-token'],
                'invoiceNumber' => 'top-level-is-forbidden',
                'invoice' => ['invoiceNumber' => 'wrong', 'lineItems' => []],
            ]
        );

        $runtime->executeInline($this->transaction(), 'verified-token');

        self::assertIsArray($captured);
        self::assertArrayNotHasKey('invoiceNumber', $captured);
        self::assertSame('21.00', $captured['amount']);
        self::assertSame('USD', $captured['currency']);
        self::assertSame('verified-token', $captured['cardData']['cardToken']);
        self::assertSame(self::OPERATION_UUID, $captured['invoice']['invoiceNumber']);
        self::assertSame('21.00', $captured['invoice']['lineItems'][0]['price']);
    }

    public function testStoredTransactionModeSelectsCredentialEvenWhenStoreModeChanged(): void
    {
        $record = OrderTransaction::allRecords()[20];
        $record['payment_mode'] = 'live';
        OrderTransaction::seed($record);
        StoreSettings::$orderMode = 'test';
        $credential = null;
        $runtime = $this->runtime(static function (string $endpoint, array $payload, string $apiToken) use (&$credential): array {
            unset($endpoint, $payload);
            $credential = $apiToken;
            return self::approved();
        });

        $result = $runtime->executeInline($this->transaction(), 'card-token');

        self::assertSame('succeeded', $result['status']);
        self::assertSame('live-api-secret', $credential);
    }

    public function testDefinitiveDeclineStaysUnpaidButAmbiguousFailureLocksReplay(): void
    {
        $declineCalls = 0;
        $declineRuntime = $this->runtime(static function () use (&$declineCalls): \WP_Error {
            $declineCalls++;
            return new \WP_Error('ys_helcim_api_error', 'Declined', [
                'kind' => 'provider',
                'http_code' => 500,
                'indeterminate' => false,
                'provider_errors' => 'Transaction Declined: CVV does not match',
                'definitive_decline' => true,
            ]);
        });

        $declined = $declineRuntime->executeInline($this->transaction(), 'declined-token');

        self::assertSame('declined', $declined['status']);
        self::assertSame(Status::TRANSACTION_PENDING, OrderTransaction::allRecords()[20]['status']);
        self::assertSame(1, $declineCalls);

        OrderTransaction::reset();
        $this->seedTransaction();
        $unknownCalls = 0;
        $unknownRuntime = $this->runtime(static function () use (&$unknownCalls): \WP_Error {
            $unknownCalls++;
            return new \WP_Error('ys_helcim_api_error', 'Timeout', [
                'kind' => 'transport',
                'indeterminate' => true,
            ]);
        }, operationUuid: '00000000-0000-4000-8000-000000000778');

        $first = $unknownRuntime->executeInline($this->transaction(), 'unknown-token');
        $replay = $unknownRuntime->executeInline($this->transaction(), 'unknown-token');
        $differentCard = $unknownRuntime->executeInline($this->transaction(), 'different-token');

        self::assertSame('indeterminate', $first['status']);
        self::assertSame('indeterminate', $replay['status']);
        self::assertSame('attention_required', $differentCard['status']);
        self::assertSame(1, $unknownCalls);
        self::assertSame(Status::TRANSACTION_PENDING, OrderTransaction::allRecords()[20]['status']);
    }

    public function testOrmSaveFalseNeverClaimsPaidAndReplayCanRepairWithoutRecharging(): void
    {
        $apiCalls = 0;
        $runtime = $this->runtime(static function () use (&$apiCalls): array {
            $apiCalls++;
            return self::approved();
        });
        OrderTransaction::$saveResult = false;

        $failed = $runtime->executeInline($this->transaction(), 'card-token');

        self::assertSame('attention_required', $failed['status']);
        self::assertSame('local_bind_failed', $failed['error_code']);
        self::assertSame(Status::TRANSACTION_PENDING, OrderTransaction::allRecords()[20]['status']);
        self::assertCount(0, StatusHelper::$syncs);

        OrderTransaction::$saveResult = true;
        $repaired = $runtime->executeInline($this->transaction(), 'card-token');

        self::assertSame('succeeded', $repaired['status'], json_encode($repaired, JSON_THROW_ON_ERROR));
        self::assertSame(1, $apiCalls);
        self::assertSame('51177123', OrderTransaction::allRecords()[20]['vendor_charge_id']);
    }

    public function testTransactionSaveWithoutOrderPaidProofRetriesDurableBinderWithoutRecharging(): void
    {
        $apiCalls = 0;
        $syncCalls = 0;
        $allowSync = false;
        $runtime = $this->runtime(
            static function () use (&$apiCalls): array {
                $apiCalls++;
                return self::approved();
            },
            statusSync: static function (Order $order, OrderTransaction $transaction) use (&$syncCalls, &$allowSync): bool {
                unset($transaction);
                $syncCalls++;
                if (!$allowSync) {
                    return false;
                }
                $order->markPaid();
                return true;
            }
        );

        $first = $runtime->executeInline($this->transaction(), 'card-token');
        self::assertSame('attention_required', $first['status']);
        self::assertSame(Status::TRANSACTION_SUCCEEDED, OrderTransaction::allRecords()[20]['status']);
        self::assertSame('pending', Order::allRecords()[10]['payment_status']);

        $allowSync = true;
        $second = $runtime->executeInline($this->transaction(), 'card-token');

        self::assertSame('succeeded', $second['status']);
        self::assertSame(1, $apiCalls);
        self::assertSame(2, $syncCalls);
        self::assertSame('paid', Order::allRecords()[10]['payment_status']);
        self::assertSame(2100, Order::allRecords()[10]['total_paid']);
    }

    public function testFluentCartPendingResetWithExactDurableProviderBindingCanRepairWithoutRecharging(): void
    {
        $apiCalls = 0;
        $allowSync = false;
        $runtime = $this->runtime(
            static function () use (&$apiCalls): array {
                ++$apiCalls;
                return self::approved();
            },
            statusSync: static function (Order $order, OrderTransaction $transaction) use (&$allowSync): bool {
                unset($transaction);
                if (!$allowSync) {
                    return false;
                }
                $order->markPaid();
                return true;
            }
        );

        $first = $runtime->executeInline($this->transaction(), 'card-token');
        self::assertSame('attention_required', $first['status']);
        self::assertSame('51177123', OrderTransaction::allRecords()[20]['vendor_charge_id']);

        // FluentCart 1.5.2 resets the existing transaction to pending on a
        // checkout retry, but preserves vendor_charge_id in its fill payload.
        $reset = OrderTransaction::allRecords()[20];
        $reset['status'] = Status::TRANSACTION_PENDING;
        OrderTransaction::seed($reset);
        $allowSync = true;

        $repaired = $runtime->executeInline($this->transaction(), 'new-token-that-must-not-be-used');

        self::assertSame('succeeded', $repaired['status'], json_encode($repaired, JSON_THROW_ON_ERROR));
        self::assertSame(1, $apiCalls);
        self::assertSame(Status::TRANSACTION_SUCCEEDED, OrderTransaction::allRecords()[20]['status']);
        self::assertSame('paid', Order::allRecords()[10]['payment_status']);
    }

    public function testAlreadySucceededRequiresCanonicalProviderIdAndNeverCallsProvider(): void
    {
        $record = OrderTransaction::allRecords()[20];
        $record['status'] = Status::TRANSACTION_SUCCEEDED;
        $record['vendor_charge_id'] = '51177123';
        OrderTransaction::seed($record);
        $order = Order::query()->where('id', 10)->first();
        self::assertInstanceOf(Order::class, $order);
        $order->markPaid();
        $apiCalls = 0;
        $runtime = $this->runtime(static function () use (&$apiCalls): array {
            $apiCalls++;
            return self::approved();
        });

        $result = $runtime->executeInline($this->transaction(), '');

        self::assertSame('succeeded', $result['status']);
        self::assertSame('51177123', $result['provider_transaction_id']);
        self::assertSame(0, $apiCalls);
        self::assertCount(0, $this->database->allRows());

        $record['vendor_charge_id'] = 'not-an-exact-id';
        OrderTransaction::seed($record);
        $invalid = $runtime->executeInline($this->transaction(), '');
        self::assertSame('attention_required', $invalid['status']);
        self::assertSame(0, $apiCalls);
    }

    public function testReconcileUsesExactTransactionAndStrictCorrelatedProofWithoutProviderCall(): void
    {
        $apiCalls = 0;
        $runtime = $this->runtime(static function () use (&$apiCalls): \WP_Error {
            $apiCalls++;
            return new \WP_Error('ys_helcim_api_error', 'Timeout', ['indeterminate' => true]);
        });
        $unknown = $runtime->executeInline($this->transaction(), 'card-token');
        self::assertSame('indeterminate', $unknown['status']);

        $proof = [
            'operation_correlation' => $unknown['operation_uuid'],
            'outcome' => 'succeeded',
            'transaction' => [
                'status' => 'APPROVED',
                'type' => 'purchase',
                'transactionId' => '51177123',
                'amount' => '21.00',
                'currency' => 'USD',
            ],
        ];
        $result = $runtime->reconcileProviderProof(
            $this->transaction(),
            $unknown['operation_uuid'],
            $proof
        );

        self::assertSame('succeeded', $result['status']);
        self::assertSame(1, $apiCalls, 'Reconciliation must never invoke payment/purchase.');
        self::assertSame('51177123', OrderTransaction::allRecords()[20]['vendor_charge_id']);
    }

    public function testReconcileRejectsWrongCorrelationWithoutBinding(): void
    {
        $runtime = $this->runtime(static fn (): \WP_Error => new \WP_Error('timeout', 'Timeout'));
        $unknown = $runtime->executeInline($this->transaction(), 'card-token');
        $result = $runtime->reconcileProviderProof($this->transaction(), $unknown['operation_uuid'], [
            'operation_correlation' => '00000000-0000-4000-8000-000000000999',
            'outcome' => 'succeeded',
            'transaction' => [
                'status' => 'APPROVED',
                'type' => 'purchase',
                'transactionId' => '51177123',
                'amount' => '21.00',
                'currency' => 'USD',
            ],
        ]);

        self::assertSame('attention_required', $result['status']);
        self::assertSame('provider_correlation_mismatch', $result['error_code']);
        self::assertSame(Status::TRANSACTION_PENDING, OrderTransaction::allRecords()[20]['status']);
    }

    public function testHostedReconciliationUsesTheSharedJournalBinderAndPurgesLegacyTokens(): void
    {
        BaseGatewaySettings::$settingsByClass[YSHelcimPaySettings::class] = [
            'test_api_token' => 'enc:test-api-secret',
        ];
        $record = OrderTransaction::allRecords()[20];
        $record['payment_method'] = 'ys_helcim';
        $record['meta'] = [
            'existing' => 'kept',
            'ys_helcim_checkout_token' => 'one-time-checkout-token',
            'ys_helcim_secret_token_enc' => 'encrypted-one-time-secret',
            'ys_helcim_card_token' => 'legacy-reusable-card-token',
        ];
        OrderTransaction::seed($record);

        $identity = [
            'gateway' => 'ys_helcim',
            'order_id' => 10,
            'transaction_id' => 20,
            'transaction_uuid' => 'fc-transaction-123',
            'amount' => 2100,
            'currency' => 'USD',
            'payment_mode' => 'test',
        ];
        $purchase = YSHelcimPurchaseOperation::fromTransaction($identity);
        self::assertInstanceOf(YSHelcimPurchaseOperation::class, $purchase);
        $operation = $purchase->repositoryRecord(self::OPERATION_UUID, hash('sha256', 'hosted-attempt'));
        self::assertIsArray($operation);
        $operation['provider_correlation_id'] = self::OPERATION_UUID;
        self::assertIsArray($this->repository->create($operation));
        self::assertTrue($this->repository->claimRemoteProcessing(self::OPERATION_UUID));

        $apiCalls = 0;
        $runtime = new YSHelcimJsPurchaseRuntime(
            settings: new YSHelcimPaySettings(),
            operations: $this->repository,
            api_request: static function () use (&$apiCalls): array {
                ++$apiCalls;
                return self::approved();
            },
            method_slug: 'ys_helcim',
            terminal_meta_keys: [
                'ys_helcim_checkout_token',
                'ys_helcim_secret_token_enc',
                'ys_helcim_card_token',
            ]
        );

        $result = $runtime->reconcileProviderProof($this->transaction(), self::OPERATION_UUID, [
            'operation_correlation' => self::OPERATION_UUID,
            'outcome' => 'succeeded',
            'transaction' => [
                'status' => 'APPROVED',
                'type' => 'purchase',
                'transactionId' => '51177123',
                'amount' => '21.00',
                'currency' => 'USD',
            ],
        ]);

        self::assertSame('succeeded', $result['status']);
        self::assertSame(0, $apiCalls, 'Hosted proof reconciliation must never call payment/purchase.');
        $stored = OrderTransaction::allRecords()[20];
        self::assertSame('kept', $stored['meta']['existing']);
        self::assertArrayNotHasKey('ys_helcim_checkout_token', $stored['meta']);
        self::assertArrayNotHasKey('ys_helcim_secret_token_enc', $stored['meta']);
        self::assertArrayNotHasKey('ys_helcim_card_token', $stored['meta']);
    }

    public function testHostedSucceededAndPaidReplayRejectsAnUnprovenTerminalMetaPurge(): void
    {
        BaseGatewaySettings::$settingsByClass[YSHelcimPaySettings::class] = [
            'test_api_token' => 'enc:test-api-secret',
        ];
        $record = OrderTransaction::allRecords()[20];
        $record['payment_method'] = 'ys_helcim';
        $record['status'] = Status::TRANSACTION_SUCCEEDED;
        $record['vendor_charge_id'] = '51177123';
        $record['meta'] = [
            'existing' => 'kept',
            'ys_helcim_checkout_token' => 'one-time-checkout-token',
            'ys_helcim_secret_token_enc' => 'encrypted-one-time-secret',
            'ys_helcim_operation_uuid' => self::OPERATION_UUID,
            'ys_helcim_initialized_at' => '2026-07-21 00:00:00',
            'ys_helcim_card_token' => 'legacy-reusable-card-token',
        ];
        OrderTransaction::seed($record);
        $order = Order::query()->where('id', 10)->first();
        self::assertInstanceOf(Order::class, $order);
        $order->markPaid();

        $identity = [
            'gateway' => 'ys_helcim',
            'order_id' => 10,
            'transaction_id' => 20,
            'transaction_uuid' => 'fc-transaction-123',
            'amount' => 2100,
            'currency' => 'USD',
            'payment_mode' => 'test',
        ];
        $purchase = YSHelcimPurchaseOperation::fromTransaction($identity);
        self::assertInstanceOf(YSHelcimPurchaseOperation::class, $purchase);
        $operation = $purchase->repositoryRecord(self::OPERATION_UUID, hash('sha256', 'hosted-attempt'));
        self::assertIsArray($operation);
        $operation['provider_correlation_id'] = self::OPERATION_UUID;
        self::assertIsArray($this->repository->create($operation));
        self::assertTrue($this->repository->claimRemoteProcessing(self::OPERATION_UUID));
        self::assertTrue($this->repository->transitionRemote(
            self::OPERATION_UUID,
            'processing',
            'succeeded',
            ['vendor_transaction_id' => '51177123']
        ));

        $runtime = new YSHelcimJsPurchaseRuntime(
            settings: new YSHelcimPaySettings(),
            operations: $this->repository,
            api_request: static fn (): \WP_Error => new \WP_Error('must_not_call_provider', 'Must not call provider'),
            method_slug: 'ys_helcim',
            terminal_meta_keys: [
                'ys_helcim_checkout_token',
                'ys_helcim_secret_token_enc',
                'ys_helcim_operation_uuid',
                'ys_helcim_initialized_at',
                'ys_helcim_card_token',
            ]
        );
        OrderTransaction::$savePersists = false;

        $result = $runtime->reconcileProviderProof($this->transaction(), self::OPERATION_UUID, [
            'operation_correlation' => self::OPERATION_UUID,
            'outcome' => 'succeeded',
            'transaction' => [
                'status' => 'APPROVED',
                'type' => 'purchase',
                'transactionId' => '51177123',
                'amount' => '21.00',
                'currency' => 'USD',
            ],
        ]);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_purchase_meta_purge_unverified', $result->get_error_code());
        self::assertArrayHasKey('ys_helcim_secret_token_enc', OrderTransaction::allRecords()[20]['meta']);
    }

    /** @param callable $apiRequest */
    private function runtime(
        callable $apiRequest,
        ?callable $payloadFilter = null,
        string $operationUuid = self::OPERATION_UUID,
        ?callable $statusSync = null
    ): YSHelcimJsPurchaseRuntime {
        return new YSHelcimJsPurchaseRuntime(
            settings: $this->settings,
            operations: $this->repository,
            api_request: $apiRequest,
            uuid_factory: static fn (): string => $operationUuid,
            clock: static fn (): string => '2026-07-21 00:00:00',
            payload_filter: $payloadFilter ?? static fn (array $payload): array => $payload,
            client_ip: static fn (): string => '203.0.113.7',
            status_sync: $statusSync
        );
    }

    private function seedServerModels(): void
    {
        $this->seedTransaction();
        Order::seed([
            'id' => 10,
            'uuid' => 'order-uuid-10',
            'status' => 'pending',
            'payment_status' => 'pending',
            'total_amount' => 2100,
            'total_paid' => 0,
            'billing_address' => (object) [
                'name' => 'Test Buyer',
                'address_1' => '1 Test Street',
                'address_2' => '',
                'city' => 'Toronto',
                'state' => 'ON',
                'postcode' => 'M5V 1A1',
                'country' => 'CA',
                'phone' => '',
                'email' => 'buyer@example.test',
            ],
            'shipping_address' => null,
            'customer' => null,
        ]);
    }

    private function seedTransaction(): void
    {
        OrderTransaction::seed([
            'id' => 20,
            'uuid' => 'fc-transaction-123',
            'order_id' => 10,
            'payment_method' => 'ys_helcim_js',
            'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
            'total' => 2100,
            'currency' => 'USD',
            'payment_mode' => 'test',
            'status' => Status::TRANSACTION_PENDING,
            'vendor_charge_id' => null,
            'payment_method_type' => '',
            'card_last_4' => '',
            'card_brand' => '',
            'meta' => ['existing' => 'kept'],
        ]);
    }

    private function transaction(): OrderTransaction
    {
        $transaction = OrderTransaction::query()->where('id', 20)->first();
        self::assertInstanceOf(OrderTransaction::class, $transaction);
        return $transaction;
    }

    /** @return array<string, mixed> */
    private static function approved(): array
    {
        return [
            'status' => 'APPROVED',
            'type' => 'purchase',
            'transactionId' => '51177123',
            'amount' => '21.00',
            'currency' => 'USD',
            'cardToken' => 'provider-returned-token',
            'approvalCode' => 'ABC123',
        ];
    }
}
