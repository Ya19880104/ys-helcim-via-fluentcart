<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Refund;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimIdempotency;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationRepository;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationScope;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOutboxRepository;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimLocalRefundRecorder;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimProviderRefundSync;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundFinalizer;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundLocalCoordinator;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundPayload;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundService;
use YangSheep\Helcim\FluentCart\Tests\Doubles\FakeWpdb;
use YangSheep\Helcim\FluentCart\Tests\Doubles\LocalRefundWpdb;

/**
 * 外部（Helcim 後台）作廢／退款同步：條件全部成立才記錄，其餘只回報、不動帳。
 */
final class ProviderRefundSyncTest extends TestCase
{
    private const PURCHASE_UUID = '29000000-0000-4000-8000-000000000002';

    private const TABLE = 'wp_ys_helcim_operations';

    private FakeWpdb $database;

    private YSHelcimOperationRepository $operations;

    /** @var array<string,mixed> */
    private array $sourceTransaction;

    /** @var array<string,mixed> */
    private array $order;

    /** @var array<int,array<string,mixed>> FluentCart 既有退款列（帳務一致性預檢用） */
    private array $refunds = [];

    /** @var array<int,array<string,mixed>> Helcim 端同 invoice 的全部交易（webhook 路徑的家族檢查用） */
    private array $family = [];

    private int $familyLookups = 0;

    /** @var array<int,string> */
    private array $recorderCalls = [];

    /** @var array<int,array{0:int,1:string,2:string}> */
    private array $notes = [];

    /** @var array<int,array{0:string,1:array<string,mixed>}> */
    private array $reviews = [];

    /** @var array<int,array{0:string,1:string,2:string}> */
    private array $localFailures = [];

    /** @var \WP_Error|null */
    private $nextRecorderError = null;

    protected function setUp(): void
    {
        $this->database = new FakeWpdb();
        $this->operations = new YSHelcimOperationRepository($this->database, static fn (): string => '2026-09-23 03:00:00');
        $this->database->insert(self::TABLE, [
            'operation_uuid' => self::PURCHASE_UUID,
            'idempotency_key' => 'ysh-purchase-000000000000000000000001',
            'scope_key' => YSHelcimOperationScope::fromBusinessKey('purchase-transaction-v1:60'),
            'active_scope_key' => YSHelcimOperationScope::fromBusinessKey('purchase-transaction-v1:60'),
            'operation_type' => 'purchase',
            'gateway' => 'ys_helcim',
            'order_id' => 48,
            'transaction_id' => 60,
            'transaction_uuid' => 'fc-txn-60',
            'parent_operation_uuid' => null,
            'amount' => 123,
            'currency' => 'USD',
            'payment_mode' => 'test',
            'remote_status' => 'succeeded',
            'local_status' => 'applied',
            'vendor_transaction_id' => '82553743',
            'source_vendor_transaction_id' => null,
            'created_at' => '2026-08-03 13:38:32',
            'updated_at' => '2026-08-03 13:39:10',
        ]);
        $this->sourceTransaction = [
            'id' => 60,
            'order_id' => 48,
            'uuid' => 'fc-txn-60',
            'payment_method' => 'ys_helcim',
            'payment_mode' => 'test',
            'currency' => 'USD',
            'status' => 'succeeded',
            'transaction_type' => 'charge',
            'total' => 123,
            'vendor_charge_id' => '82553743',
            'meta' => '[]',
        ];
        $this->order = [
            'id' => 48,
            'currency' => 'USD',
            'total_paid' => 123,
            'total_refund' => 0,
        ];
        $this->family = [[
            'transactionId' => '82553743',
            'type' => 'purchase',
            'status' => 'APPROVED',
            'amount' => '1.23',
            'currency' => 'USD',
            'invoiceNumber' => self::PURCHASE_UUID,
        ]];
    }

    public function testRecordsAnApprovedFullReverseAsAChildOfTheVoidedPurchase(): void
    {
        $result = $this->sync()->recordProof($this->proof(), $this->bindings());

        self::assertSame('recorded', $result['status']);
        self::assertSame('recorded', $result['reason']);
        self::assertSame('85267563', $result['transaction_id']);
        self::assertSame('reverse', $result['provider_action']);
        self::assertSame(48, $result['order_id']);

        $row = $this->rowByVendor('85267563');
        self::assertSame($result['operation_uuid'], $row['operation_uuid']);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $row['operation_uuid']);
        self::assertSame('reverse', $row['operation_type']);
        self::assertSame(self::PURCHASE_UUID, $row['parent_operation_uuid']);
        self::assertSame('succeeded', $row['remote_status']);
        self::assertSame('82553743', $row['source_vendor_transaction_id']);
        self::assertSame(123, $row['amount']);
        self::assertSame('USD', $row['currency']);
        self::assertSame('ys_helcim', $row['gateway']);
        self::assertSame('test', $row['payment_mode']);
        self::assertSame(60, $row['transaction_id']);
        self::assertSame(YSHelcimOperationScope::fromBusinessKey('refund-order:48'), $row['scope_key']);
        self::assertNull($row['encrypted_material']);
        self::assertSame(
            YSHelcimIdempotency::generate('reverse', 'fc-txn-60', 123, 'test', $row['operation_uuid']),
            $row['idempotency_key']
        );
        $payload = json_decode($row['local_payload'], true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(YSHelcimRefundPayload::normalize($payload), $payload);
        self::assertSame('Voided in Helcim (Helcim transaction 85267563).', $payload['reason']);
        self::assertSame([], $payload['item_ids']);
        self::assertFalse($payload['manage_stock']);
        self::assertSame(0, $payload['actor_user_id']);
        self::assertSame(hash('sha256', $row['local_payload']), $row['local_payload_hash']);

        self::assertSame([$row['operation_uuid']], $this->recorderCalls);
        self::assertCount(1, $this->notes);
        self::assertSame(48, $this->notes[0][0]);
        self::assertSame('Helcim reverse recorded from provider records', $this->notes[0][1]);
        self::assertStringContainsString('1.23 USD', $this->notes[0][2]);
        self::assertStringContainsString('85267563', $this->notes[0][2]);
        self::assertStringContainsString('store-admin', $this->notes[0][2]);
        self::assertStringContainsString('2026-09-22 10:31:05', $this->notes[0][2]);
        self::assertSame([], $this->reviews);
        self::assertSame(null, $this->activeRefundScopeRow(), 'The refund scope must be released after local application.');
    }

    public function testRecordsASettledPartialRefundWithoutAParentOperation(): void
    {
        $result = $this->sync()->recordProof(
            $this->proof(['type' => 'refund', 'transactionId' => '85300001', 'amount' => '0.50']),
            $this->bindings()
        );

        self::assertSame('recorded', $result['status']);
        $row = $this->rowByVendor('85300001');
        self::assertSame('refund', $row['operation_type']);
        self::assertNull($row['parent_operation_uuid']);
        self::assertSame(50, $row['amount']);
        self::assertSame('Helcim refund recorded from provider records', $this->notes[0][1]);
        self::assertStringContainsString('0.50 USD', $this->notes[0][2]);
        $payload = json_decode($row['local_payload'], true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('Refunded in Helcim (Helcim transaction 85300001).', $payload['reason']);
    }

    /** @return array<string, array{array<string,mixed>, string, string}> */
    public static function ignoredRecords(): array
    {
        return [
            'purchase record' => [['type' => 'purchase'], 'skipped', 'not_a_refund'],
            'verify record' => [['type' => 'verify'], 'skipped', 'not_a_refund'],
            'declined refund' => [['type' => 'refund', 'status' => 'DECLINED'], 'skipped', 'not_approved'],
            'missing status' => [['status' => null], 'skipped', 'not_approved'],
            'missing transaction id' => [['transactionId' => null], 'skipped', 'invalid_provider_record'],
            'no invoice number' => [['invoiceNumber' => null], 'unrelated', 'no_operation_correlation'],
            'non-uuid invoice number' => [['invoiceNumber' => 'INV-14'], 'unrelated', 'no_operation_correlation'],
            'unknown operation uuid' => [['invoiceNumber' => '00000000-0000-4000-8000-000000000099'], 'unrelated', 'no_operation_correlation'],
        ];
    }

    /** @param array<string,mixed> $overrides */
    #[DataProvider('ignoredRecords')]
    public function testRecordsThatAreNotExternalRefundsNeverWrite(array $overrides, string $status, string $reason): void
    {
        $before = $this->database->allRows();

        $result = $this->sync()->recordProof($this->proof($overrides), $this->bindings());

        self::assertSame($status, $result['status']);
        self::assertSame($reason, $result['reason']);
        self::assertSame($before, $this->database->allRows());
        self::assertSame([], $this->recorderCalls);
        self::assertSame([], $this->notes);
        self::assertSame([], $this->reviews);
    }

    /** @return array<string, array{string, string}> */
    public static function reviewCases(): array
    {
        return [
            'purchase declined' => ['purchase_declined', 'purchase_not_applied'],
            'purchase canceled' => ['purchase_canceled', 'purchase_not_applied'],
            'currency mismatch' => ['currency', 'currency_mismatch'],
            'amount above remaining' => ['amount', 'amount_exceeds_remaining'],
            'order balance already refunded' => ['order_refunded', 'amount_exceeds_remaining'],
            'reverse for less than the full charge' => ['partial_reverse', 'reverse_not_full'],
            'reverse after an earlier refund' => ['reverse_after_refund', 'reverse_not_full'],
            'binding for another gateway' => ['binding', 'credential_binding_mismatch'],
            'local charge id differs' => ['vendor_charge', 'local_transaction_mismatch'],
            'local charge gateway differs' => ['source_gateway', 'local_transaction_mismatch'],
            'invoice points at a refund operation' => ['refund_correlation', 'operation_correlation_conflict'],
            'invalid amount' => ['invalid_amount', 'invalid_amount'],
            'provider id belongs to the purchase itself' => ['same_id', 'provider_id_conflict'],
            'purchase without a provider id' => ['purchase_without_vendor', 'purchase_identity_invalid'],
            'order refund total disagrees with refund rows' => ['order_total_drift', 'accounting_drift'],
            'charge refunded total disagrees with refund rows' => ['source_meta_drift', 'accounting_drift'],
            'unreadable FluentCart refund row' => ['unreadable_refund_row', 'accounting_drift'],
        ];
    }

    #[DataProvider('reviewCases')]
    public function testReviewConditionsAreLoggedAndNeverWrite(string $case, string $reason): void
    {
        $proof = $this->proof();
        $bindings = $this->bindings();
        switch ($case) {
            case 'purchase_declined':
                $this->database->update(self::TABLE, ['remote_status' => 'declined', 'local_status' => 'pending'], ['operation_uuid' => self::PURCHASE_UUID]);
                break;
            case 'purchase_canceled':
                $this->database->update(self::TABLE, ['remote_status' => 'canceled', 'local_status' => 'pending'], ['operation_uuid' => self::PURCHASE_UUID]);
                break;
            case 'currency':
                $proof['currency'] = 'CAD';
                break;
            case 'amount':
                $proof = $this->proof(['type' => 'refund', 'amount' => '2.00']);
                break;
            case 'order_refunded':
                $this->refunds = [['id' => 61, 'status' => 'refunded', 'total' => 100, 'vendor_charge_id' => '85000001', 'meta' => '{"parent_id":60}']];
                $this->sourceTransaction['meta'] = '{"refunded_total":100}';
                $this->order['total_refund'] = 100;
                $proof = $this->proof(['type' => 'refund', 'amount' => '0.50']);
                break;
            case 'partial_reverse':
                $proof['amount'] = '1.00';
                break;
            case 'reverse_after_refund':
                $this->refunds = [['id' => 61, 'status' => 'refunded', 'total' => 10, 'vendor_charge_id' => '85000001', 'meta' => '{"parent_id":60}']];
                $this->sourceTransaction['meta'] = '{"refunded_total":10}';
                $this->order['total_refund'] = 10;
                $proof['amount'] = '1.13';
                break;
            case 'order_total_drift':
                $this->order['total_refund'] = 50;
                break;
            case 'source_meta_drift':
                $this->sourceTransaction['meta'] = '{"refunded_total":20}';
                break;
            case 'unreadable_refund_row':
                $this->refunds = [['id' => 61, 'status' => 'refunded', 'total' => 'ten', 'vendor_charge_id' => '85000001', 'meta' => '{"parent_id":60}']];
                $this->order['total_refund'] = 10;
                break;
            case 'binding':
                $bindings = [['gateway' => 'ys_helcim_js', 'mode' => 'test']];
                break;
            case 'vendor_charge':
                $this->sourceTransaction['vendor_charge_id'] = '82553744';
                break;
            case 'source_gateway':
                $this->sourceTransaction['payment_method'] = 'ys_helcim_js';
                break;
            case 'refund_correlation':
                $this->database->update(self::TABLE, ['operation_type' => 'refund'], ['operation_uuid' => self::PURCHASE_UUID]);
                break;
            case 'invalid_amount':
                $proof['amount'] = '-1.23';
                break;
            case 'same_id':
                $proof['transactionId'] = '82553743';
                $proof['type'] = 'refund';
                break;
            case 'purchase_without_vendor':
                $this->database->update(self::TABLE, ['vendor_transaction_id' => null], ['operation_uuid' => self::PURCHASE_UUID]);
                break;
        }
        $before = $this->database->allRows();

        $result = $this->sync()->recordProof($proof, $bindings);

        self::assertSame('review', $result['status']);
        self::assertSame($reason, $result['reason']);
        self::assertSame($before, $this->database->allRows());
        self::assertSame([], $this->recorderCalls);
        self::assertSame([], $this->notes);
        self::assertCount(1, $this->reviews);
        self::assertSame($reason, $this->reviews[0][1]['reason']);
    }

    public function testAProviderIdAlreadyInTheJournalIsAlreadyRecordedWithoutNewRows(): void
    {
        // 外掛自己發起、已套用的作廢：webhook 送來同一筆 Helcim 交易時只確認、不重記。
        $this->database->insert(self::TABLE, [
            'operation_uuid' => '5300cafe-0000-4000-8000-000000000003',
            'idempotency_key' => 'ysh-reverse-00000000000000000000000001',
            'scope_key' => YSHelcimOperationScope::fromBusinessKey('refund-order:48'),
            'active_scope_key' => null,
            'operation_type' => 'reverse',
            'gateway' => 'ys_helcim',
            'order_id' => 48,
            'transaction_id' => 60,
            'amount' => 123,
            'currency' => 'USD',
            'payment_mode' => 'test',
            'remote_status' => 'succeeded',
            'local_status' => 'applied',
            'vendor_transaction_id' => '85267563',
        ]);
        $before = $this->database->allRows();

        $result = $this->sync()->recordProof($this->proof(), $this->bindings());

        self::assertSame('already_recorded', $result['status']);
        self::assertSame('5300cafe-0000-4000-8000-000000000003', $result['operation_uuid']);
        self::assertSame($before, $this->database->allRows());
        self::assertSame([], $this->recorderCalls);
        self::assertSame([], $this->notes);
    }

    public function testAHelcimRefundAlreadyInFluentCartIsNotRecordedAgain(): void
    {
        // FluentCart 已有這筆 Helcim 交易的退款（例如舊版或其他途徑記過）：不建作業列、不佔退款鎖。
        $this->refunds = [['id' => 61, 'status' => 'refunded', 'total' => 123, 'vendor_charge_id' => '85267563', 'meta' => '{"parent_id":60}']];
        $this->sourceTransaction['meta'] = '{"refunded_total":123}';
        $this->order['total_refund'] = 123;
        $before = $this->database->allRows();

        $result = $this->sync()->recordProof($this->proof(), $this->bindings());

        self::assertSame('already_recorded', $result['status']);
        self::assertSame('already_in_fluentcart', $result['reason']);
        self::assertSame($before, $this->database->allRows());
        self::assertSame([], $this->recorderCalls);
        self::assertSame([], $this->reviews);
        self::assertNull($this->activeRefundScopeRow());
    }

    public function testProviderRecordedOperationsAreRecognisedOnlyByTheirDerivedIdentity(): void
    {
        $result = $this->sync()->recordProof($this->proof(), $this->bindings());
        $external = $this->rowByVendor('85267563');
        $purchase = $this->operations->findByUuid(self::PURCHASE_UUID);

        self::assertSame('recorded', $result['status']);
        self::assertTrue(YSHelcimProviderRefundSync::isProviderRecordedOperation($external));
        self::assertFalse(YSHelcimProviderRefundSync::isProviderRecordedOperation($purchase));
        self::assertFalse(YSHelcimProviderRefundSync::isProviderRecordedOperation(['operation_uuid' => '5300cafe-0000-4000-8000-000000000003'] + $external));
        self::assertFalse(YSHelcimProviderRefundSync::isProviderRecordedOperation(['vendor_transaction_id' => '85267564'] + $external));
        self::assertFalse(YSHelcimProviderRefundSync::isProviderRecordedOperation(['payment_mode' => 'live'] + $external));
    }

    public function testAStuckHelcimSideRefundBlocksNewRefundsWithAnExplicitReasonAndShowsUpForAttention(): void
    {
        // 外部列本地寫入以 review 類失敗收場 → 持有退款鎖；同訂單再發起面板退款要說清楚原因，且要出現在管理員通知。
        $this->nextRecorderError = new \WP_Error('ys_helcim_local_accounting_drift', 'Drift.');
        $failed = $this->sync()->recordProof($this->proof(), $this->bindings());
        self::assertSame('review', $failed['status']);
        self::assertNotNull($this->activeRefundScopeRow());

        $apiCalls = 0;
        $service = new YSHelcimRefundService(
            $this->operations,
            static function () use (&$apiCalls): array {
                ++$apiCalls;
                return [];
            },
            static fn (): string => '00000000-0000-4000-8000-000000000077',
            static fn (): string => '2026-09-23 03:05:00'
        );
        $refused = $service->execute([
            'operation_uuid' => '00000000-0000-4000-8000-000000000066',
            'gateway' => 'ys_helcim',
            'order_id' => 48,
            'transaction_id' => 60,
            'transaction_uuid' => 'fc-txn-60',
            'vendor_transaction_id' => '82553743',
            'amount' => 123,
            'transaction_total' => 123,
            'refunded_total' => 0,
            'remaining_refundable' => 123,
            'currency' => 'USD',
            'payment_mode' => 'test',
            'order_item_quantities' => [81 => 1],
            'current_mode' => 'test',
            'api_token' => 'unit-test-api-token',
            'ip_address' => '203.0.113.9',
            'local_payload' => ['reason' => 'Customer request'],
        ]);

        self::assertInstanceOf(\WP_Error::class, $refused);
        self::assertSame('ys_helcim_provider_refund_pending', $refused->get_error_code());
        self::assertSame(409, $refused->get_error_data()['status']);
        self::assertStringContainsString('made directly in Helcim', $refused->get_error_message());
        self::assertSame(0, $apiCalls);

        $attention = $this->operations->findRefundsAwaitingLocalRecording('2026-09-23 03:10:00', 10);
        self::assertIsArray($attention);
        self::assertCount(1, $attention);
        self::assertSame($failed['operation_uuid'], $attention[0]['operation_uuid']);
        self::assertTrue(YSHelcimProviderRefundSync::isProviderRecordedOperation($attention[0]));
        self::assertSame([], $this->operations->findRefundsAwaitingLocalRecording('2026-09-23 02:59:59', 10));

        // 管理員的解法：修正原因後按「從 Helcim 同步退款」→ 同一作業重跑記錄 → 釋放退款鎖。
        $resumed = $this->sync()->recordProof($this->proof(), $this->bindings(), self::PURCHASE_UUID);
        self::assertSame('recorded', $resumed['status']);
        self::assertSame($failed['operation_uuid'], $resumed['operation_uuid']);
        self::assertNull($this->activeRefundScopeRow());
    }

    public function testAnUnfinishedFluentCartRefundWithTheSameHelcimIdNeedsReviewInsteadOfCountingAsRecorded(): void
    {
        // FluentCart 有同一個 Helcim 編號的退款列，但還沒完成（pending）：帳面其實沒有這筆退款。
        // 不可當成「已記錄」靜默略過（Helcim 已退出去的錢會永遠漏記），也不可再建一筆 → 交給人工。
        $this->refunds = [['id' => 61, 'status' => 'pending', 'total' => 123, 'vendor_charge_id' => '85267563', 'meta' => '{"parent_id":60}']];
        $before = $this->database->allRows();

        $result = $this->sync()->recordProof($this->proof(), $this->bindings());
        $webhook = $this->sync()->reconcileWebhook($this->proof(), '85267563', $this->bindings());

        self::assertSame('review', $result['status']);
        self::assertSame('fluentcart_refund_row_not_final', $result['reason']);
        self::assertSame(409, $webhook['code']);
        self::assertSame($before, $this->database->allRows());
        self::assertSame([], $this->recorderCalls);
        self::assertSame([], $this->notes);
        self::assertNull($this->activeRefundScopeRow());
        self::assertCount(2, $this->reviews);
        self::assertSame('fluentcart_refund_row_not_final', $this->reviews[0][1]['reason']);
        self::assertSame(48, $this->reviews[0][1]['order_id']);
        self::assertSame('85267563', $this->reviews[0][1]['provider_transaction_id']);

        // 同編號裡有已完成的列（不論排在前或後）時仍以它為準：已記錄，不建列、不送人工。
        $this->reviews = [];
        $this->refunds = [
            ['id' => 61, 'status' => 'failed', 'total' => 123, 'vendor_charge_id' => '85267563', 'meta' => '{"parent_id":60}'],
            ['id' => 62, 'status' => 'refunded', 'total' => 123, 'vendor_charge_id' => '85267563', 'meta' => '{"parent_id":60}'],
        ];
        $this->sourceTransaction['meta'] = '{"refunded_total":123}';
        $this->order['total_refund'] = 123;

        $twin = $this->sync()->recordProof($this->proof(), $this->bindings());

        self::assertSame('already_recorded', $twin['status']);
        self::assertSame('already_in_fluentcart', $twin['reason']);
        self::assertSame($before, $this->database->allRows());
        self::assertSame([], $this->reviews);
    }

    /** @return array<string, array{?string}> */
    public static function refundStatusesInTheInvoiceFamily(): array
    {
        return [
            'reversed refund' => ['REVERSED'],
            'declined refund' => ['DECLINED'],
            'refund without a status' => [null],
        ];
    }

    #[DataProvider('refundStatusesInTheInvoiceFamily')]
    public function testAVoidIsSentToReviewWhenTheInvoiceFamilyHasAnyRefund(?string $refundStatus): void
    {
        // 本機無法證明 Helcim 作廢一筆退款之後，原退款紀錄的狀態是否仍是 APPROVED：
        // 家族裡只要有任何退款紀錄（不論狀態），全額作廢就交給人工，不當成「作廢原付款」入帳。
        $this->family[] = $this->proof(['type' => 'refund', 'transactionId' => '85300001', 'amount' => '1.23', 'status' => $refundStatus]);
        $void = $this->proof(['type' => 'reverse', 'transactionId' => '85300002', 'amount' => '1.23']);
        $this->family[] = $void;
        $before = $this->database->allRows();

        $webhookPath = $this->sync()->recordProof($void, $this->bindings());
        $summary = $this->sync()->syncOrder(48);

        self::assertSame('review', $webhookPath['status']);
        self::assertSame('reverse_of_refund', $webhookPath['reason']);
        self::assertSame('needs_review', $summary['status']);
        self::assertContains(
            ['transaction_id' => '85300002', 'provider_action' => 'reverse', 'reason' => 'reverse_of_refund'],
            $summary['review']
        );
        self::assertSame([], $summary['recorded']);
        self::assertSame($before, $this->database->allRows());
        self::assertSame([], $this->recorderCalls);
    }

    public function testAHelcimSideReverseIsNeverMistakenForTheReverseChildOfAPluginRefund(): void
    {
        // 外掛自己的「退款→作廢」交棒以退款作業 UUID 找子列；外部作廢掛在 purchase 作業底下，兩者不會互相誤認。
        $result = $this->sync()->recordProof($this->proof(), $this->bindings());

        self::assertSame('recorded', $result['status']);
        self::assertNull($this->operations->findChildByParent('00000000-0000-4000-8000-000000000066', 'reverse'));
        $underPurchase = $this->operations->findChildByParent(self::PURCHASE_UUID, 'reverse');
        self::assertIsArray($underPurchase);
        self::assertSame($result['operation_uuid'], $underPurchase['operation_uuid']);
    }

    /** @return array<string, array{array<int,string>}> */
    public static function refundThenVoidArrivalOrders(): array
    {
        return [
            'void arrives first' => [['void', 'refund']],
            'refund arrives first' => [['refund', 'void']],
        ];
    }

    /** @param array<int,string> $order */
    #[DataProvider('refundThenVoidArrivalOrders')]
    public function testAVoidedRefundIsNeverRecordedAsAVoidedPayment(array $order): void
    {
        // Helcim 也能作廢「未結批的退款」：紀錄同樣是 reverse、同 invoice、金額＝退款金額。
        // 全額退款後立刻作廢該退款 → 兩筆互相抵銷，FluentCart 不得顯示已退款。
        $refund = $this->proof(['type' => 'refund', 'transactionId' => '85300001', 'amount' => '1.23']);
        $void = $this->proof(['type' => 'reverse', 'transactionId' => '85300002', 'amount' => '1.23']);
        $this->family[] = $refund;
        $this->family[] = $void;
        $before = $this->database->allRows();
        $sync = $this->sync();

        $results = [];
        foreach ($order as $which) {
            $results[$which] = $sync->recordProof('void' === $which ? $void : $refund, $this->bindings());
        }

        self::assertSame('review', $results['void']['status']);
        self::assertSame('reverse_of_refund', $results['void']['reason']);
        self::assertSame('review', $results['refund']['status']);
        self::assertSame('refund_was_voided', $results['refund']['reason']);
        self::assertSame($before, $this->database->allRows());
        self::assertSame([], $this->recorderCalls);
        self::assertCount(2, $this->reviews);
    }

    public function testManualSyncFlagsAVoidedRefundInsteadOfRecordingEitherHalf(): void
    {
        $this->family[] = $this->proof(['type' => 'refund', 'transactionId' => '85300001', 'amount' => '1.23']);
        $this->family[] = $this->proof(['type' => 'reverse', 'transactionId' => '85300002', 'amount' => '1.23']);

        $summary = $this->sync()->syncOrder(48);

        self::assertSame('needs_review', $summary['status']);
        self::assertSame([
            ['transaction_id' => '85300001', 'provider_action' => 'refund', 'reason' => 'refund_was_voided'],
            ['transaction_id' => '85300002', 'provider_action' => 'reverse', 'reason' => 'reverse_of_refund'],
        ], $summary['review']);
        self::assertSame([], $summary['recorded']);
        self::assertSame([], $this->recorderCalls);
    }

    public function testAVoidAfterARecordedRefundIsFlaggedForReviewNotRecorded(): void
    {
        // 退款先記進 FluentCart，之後才在 Helcim 作廢該退款：作廢不得被當成「作廢原付款」再記一次。
        $refund = $this->proof(['type' => 'refund', 'transactionId' => '85300001', 'amount' => '0.50']);
        $this->family[] = $refund;
        $sync = $this->sync();
        self::assertSame('recorded', $sync->recordProof($refund, $this->bindings())['status']);
        $this->refunds = [['id' => 61, 'status' => 'refunded', 'total' => 50, 'vendor_charge_id' => '85300001', 'meta' => '{"parent_id":60}']];
        $this->sourceTransaction['meta'] = '{"refunded_total":50}';
        $this->order['total_refund'] = 50;
        $void = $this->proof(['type' => 'reverse', 'transactionId' => '85300002', 'amount' => '0.50']);
        $this->family[] = $void;

        $result = $sync->recordProof($void, $this->bindings());

        self::assertSame('review', $result['status']);
        self::assertContains($result['reason'], ['reverse_of_refund', 'reverse_not_full']);
        self::assertCount(1, $this->recorderCalls);
    }

    public function testAFamilyWithOnlyThePurchaseAndItsVoidStillRecordsTheVoid(): void
    {
        $this->family[] = $this->proof();

        $result = $this->sync()->recordProof($this->proof(), $this->bindings());

        self::assertSame('recorded', $result['status']);
        self::assertSame(1, $this->familyLookups);
    }

    public function testTheWebhookPathRetriesWhenTheInvoiceFamilyCannotBeListed(): void
    {
        $sync = $this->sync(
            static fn (): string => 'api-token-test',
            static fn (): \WP_Error => new \WP_Error('ys_helcim_api_error', 'Timed out.')
        );
        $before = $this->database->allRows();

        $result = $sync->recordProof($this->proof(), $this->bindings());
        $webhook = $sync->reconcileWebhook($this->proof(), '85267563', $this->bindings());

        self::assertSame('retry', $result['status']);
        self::assertSame('provider_family_unavailable', $result['reason']);
        self::assertSame(503, $webhook['code']);
        self::assertSame($before, $this->database->allRows());
        self::assertSame([], $this->reviews);
    }

    public function testAProviderIdCollisionAcrossAccountsIsLoggedForReviewNotSilentlyAcknowledged(): void
    {
        // test／live 或兩個 gateway 的數字交易編號可能相同：日誌裡同編號的列屬於別的帳號與訂單時，不可回 already_recorded。
        $this->database->insert(self::TABLE, [
            'operation_uuid' => '7d300000-0000-4000-8000-000000000005',
            'idempotency_key' => 'ysh-reverse-00000000000000000000000077',
            'scope_key' => YSHelcimOperationScope::fromBusinessKey('refund-order:99'),
            'active_scope_key' => null,
            'operation_type' => 'reverse',
            'gateway' => 'ys_helcim',
            'order_id' => 99,
            'transaction_id' => 199,
            'amount' => 123,
            'currency' => 'USD',
            'payment_mode' => 'live',
            'remote_status' => 'succeeded',
            'local_status' => 'applied',
            'vendor_transaction_id' => '85267563',
        ]);
        $before = $this->database->allRows();

        $result = $this->sync()->recordProof($this->proof(), $this->bindings());
        $webhook = $this->sync()->reconcileWebhook($this->proof(), '85267563', $this->bindings());

        self::assertSame('review', $result['status']);
        self::assertSame('provider_id_conflict', $result['reason']);
        self::assertSame(409, $webhook['code']);
        self::assertSame($before, $this->database->allRows());
        self::assertSame('provider_id_conflict', $this->reviews[0][1]['reason']);
    }

    /** @return array<string, array{string, string}> */
    public static function convergingPurchaseStates(): array
    {
        return [
            'created' => ['created', 'pending'],
            'processing' => ['processing', 'pending'],
            'indeterminate' => ['indeterminate', 'pending'],
            'succeeded but not applied' => ['succeeded', 'pending'],
            'succeeded while applying' => ['succeeded', 'applying'],
        ];
    }

    #[DataProvider('convergingPurchaseStates')]
    public function testARefundForAPurchaseThatIsStillConvergingIsRetriedLater(string $remote, string $local): void
    {
        $this->database->update(self::TABLE, ['remote_status' => $remote, 'local_status' => $local], ['operation_uuid' => self::PURCHASE_UUID]);
        $before = $this->database->allRows();

        $result = $this->sync()->recordProof($this->proof(), $this->bindings());
        $webhook = $this->sync()->reconcileWebhook($this->proof(), '85267563', $this->bindings());

        self::assertSame('retry', $result['status']);
        self::assertSame('purchase_not_final', $result['reason']);
        self::assertSame(503, $webhook['code']);
        self::assertSame($before, $this->database->allRows());
        self::assertSame([], $this->reviews);
    }

    public function testManualSyncExplainsAPaymentTakenBeforeTheOperationJournal(): void
    {
        $counted = [];
        $sync = new YSHelcimProviderRefundSync(
            $this->operations,
            fn (): array => ['transaction' => $this->sourceTransaction, 'order' => $this->order, 'refunds' => $this->refunds],
            static fn (): array => [],
            null,
            null,
            null,
            static fn (): string => 'api-token-test',
            static fn (): array => ['data' => []],
            static function (): void {
            },
            static function (int $orderId) use (&$counted): int {
                $counted[] = $orderId;
                return 47 === $orderId ? 1 : 0;
            }
        );

        $legacy = $sync->syncOrder(47);
        $none = $sync->syncOrder(46);
        $withJournal = $sync->syncOrder(48);

        self::assertSame('legacy_payment', $legacy['status']);
        self::assertSame(1, $legacy['legacy_payments']);
        self::assertSame(0, $legacy['helcim_payments']);
        self::assertSame('nothing_new', $none['status']);
        self::assertSame('nothing_new', $withJournal['status']);
        self::assertSame([47, 46], $counted, 'Orders with an applied purchase never need the legacy check.');
    }

    public function testManualSyncFirstResumesAnInterruptedHelcimSideRecordEvenWhenHelcimIsUnreachable(): void
    {
        // 上次同步在建列後、本地寫入前中斷 → 外部列 pending 持鎖；這次同步起頭先重跑它，不必等 Helcim 回應。
        $this->nextRecorderError = new \WP_Error('ys_helcim_local_storage_unavailable', 'Interrupted.');
        $first = $this->sync()->recordProof($this->proof(), $this->bindings());
        self::assertSame('retry', $first['status']);
        self::assertNotNull($this->activeRefundScopeRow());

        $offline = $this->sync(
            static fn (): string => 'api-token-test',
            static fn (): \WP_Error => new \WP_Error('ys_helcim_api_error', 'Helcim unreachable.')
        )->syncOrder(48);

        self::assertSame([['transaction_id' => '85267563', 'provider_action' => 'reverse', 'reason' => 'recorded']], $offline['recorded']);
        self::assertSame([['transaction_id' => '', 'provider_action' => '', 'reason' => 'provider_lookup_failed']], $offline['retry']);
        self::assertNull($this->activeRefundScopeRow());

        $this->family[] = $this->proof();
        $online = $this->sync()->syncOrder(48);
        self::assertSame('nothing_new', $online['status']);
        self::assertSame([['transaction_id' => '85267563', 'provider_action' => 'reverse', 'reason' => 'already_recorded']], $online['already_recorded']);
        self::assertSame([$first['operation_uuid'], $first['operation_uuid']], $this->recorderCalls);
    }

    public function testManualSyncCoversEveryAppliedPurchaseAndRefusesAnOversizedProviderList(): void
    {
        $this->database->insert(self::TABLE, [
            'operation_uuid' => '0b6d0c4e-2f3a-4c5b-8d7e-9f0a1b2c3d4e',
            'idempotency_key' => 'ysh-purchase-000000000000000000000002',
            'scope_key' => YSHelcimOperationScope::fromBusinessKey('purchase-transaction-v1:61'),
            'active_scope_key' => YSHelcimOperationScope::fromBusinessKey('purchase-transaction-v1:61'),
            'operation_type' => 'purchase',
            'gateway' => 'ys_helcim_js',
            'order_id' => 48,
            'transaction_id' => 61,
            'amount' => 100,
            'currency' => 'USD',
            'payment_mode' => 'test',
            'remote_status' => 'succeeded',
            'local_status' => 'applied',
            'vendor_transaction_id' => '82553799',
        ]);
        $this->database->insert(self::TABLE, [
            'operation_uuid' => '1c7e1d5f-3a4b-4d6c-9e8f-0a1b2c3d4e5f',
            'idempotency_key' => 'ysh-purchase-000000000000000000000003',
            'scope_key' => YSHelcimOperationScope::fromBusinessKey('purchase-transaction-v1:62'),
            'active_scope_key' => YSHelcimOperationScope::fromBusinessKey('purchase-transaction-v1:62'),
            'operation_type' => 'purchase',
            'gateway' => 'not_helcim',
            'order_id' => 48,
            'transaction_id' => 62,
            'amount' => 100,
            'currency' => 'USD',
            'payment_mode' => 'test',
            'remote_status' => 'succeeded',
            'local_status' => 'applied',
            'vendor_transaction_id' => '82553800',
        ]);
        $listed = [];
        $sync = $this->sync(
            static fn (string $gateway, string $mode): string => $gateway . '-' . $mode . '-token',
            function (string $invoice, string $token) use (&$listed): array {
                $listed[] = [$invoice, $token];
                $records = [];
                if (self::PURCHASE_UUID === $invoice) {
                    // 上限＋1 筆：一筆可以入帳的作廢，加上 100 筆被拒絕的付款嘗試。若被截斷，作廢就會被記錄。
                    $records[] = $this->proof();
                    for ($i = 1; $i <= 100; ++$i) {
                        $records[] = $this->proof(['type' => 'purchase', 'status' => 'DECLINED', 'transactionId' => (string) (86000000 + $i)]);
                    }
                    return ['data' => $records];
                }
                // 剛好等於上限：照常逐筆判斷。
                for ($i = 1; $i <= 100; ++$i) {
                    $records[] = $this->proof(['type' => 'refund', 'status' => 'DECLINED', 'transactionId' => (string) (87000000 + $i), 'invoiceNumber' => $invoice]);
                }
                return ['data' => $records];
            }
        );
        $before = $this->database->allRows();

        $summary = $sync->syncOrder(48);

        self::assertSame(3, $summary['helcim_payments']);
        self::assertSame([
            [self::PURCHASE_UUID, 'ys_helcim-test-token'],
            ['0b6d0c4e-2f3a-4c5b-8d7e-9f0a1b2c3d4e', 'ys_helcim_js-test-token'],
        ], $listed);
        self::assertSame([['transaction_id' => '', 'provider_action' => '', 'reason' => 'invalid_purchase_operation']], $summary['review']);
        self::assertSame(
            [['transaction_id' => '', 'provider_action' => '', 'reason' => 'provider_lookup_failed']],
            $summary['retry'],
            'A provider list longer than the limit is retried, never truncated.'
        );
        self::assertCount(100, $summary['skipped'], 'A provider list exactly at the limit is still judged record by record.');
        self::assertSame([], $summary['recorded']);
        self::assertSame('needs_review', $summary['status']);
        self::assertSame([], $this->recorderCalls);
        self::assertSame($before, $this->database->allRows());
    }

    public function testAnInvoiceFamilyLongerThanTheLimitIsRetriedInsteadOfTruncated(): void
    {
        // 家族判斷要看到同一 invoice 的全部紀錄。這裡決定結果的已核准退款排在第 101 筆：
        // 若截斷成 100 筆，它會被丟掉、全額作廢會被誤記成「作廢原付款」；超過上限就稍後重送。
        $records = [];
        for ($i = 1; $i <= 100; ++$i) {
            $records[] = $this->proof(['type' => 'purchase', 'status' => 'DECLINED', 'transactionId' => (string) (86000000 + $i)]);
        }
        $records[] = $this->proof(['type' => 'refund', 'transactionId' => '85300001', 'amount' => '1.23']);
        $sync = $this->sync(
            static fn (): string => 'api-token-test',
            static fn (): array => ['data' => $records]
        );
        $before = $this->database->allRows();

        $result = $sync->recordProof($this->proof(), $this->bindings());
        $webhook = $sync->reconcileWebhook($this->proof(), '85267563', $this->bindings());

        self::assertSame('retry', $result['status']);
        self::assertSame('provider_family_unavailable', $result['reason']);
        self::assertSame(503, $webhook['code']);
        self::assertSame($before, $this->database->allRows());
        self::assertSame([], $this->recorderCalls);
    }

    public function testReplayingTheSameProviderRecordIsIdempotent(): void
    {
        $sync = $this->sync();
        $first = $sync->recordProof($this->proof(), $this->bindings());
        $rowsAfterFirst = count($this->database->allRows());
        $second = $sync->recordProof($this->proof(), $this->bindings());
        $third = $sync->recordProof($this->proof(), $this->bindings(), self::PURCHASE_UUID);

        self::assertSame('recorded', $first['status']);
        self::assertSame('already_recorded', $second['status']);
        self::assertSame('already_recorded', $third['status']);
        self::assertSame($first['operation_uuid'], $second['operation_uuid']);
        self::assertSame(2, $rowsAfterFirst);
        self::assertCount(2, $this->database->allRows());
        self::assertCount(1, $this->recorderCalls);
        self::assertCount(1, $this->notes);
    }

    public function testAPluginRefundInFlightOnTheSameOrderMeansRetryLater(): void
    {
        $this->database->insert(self::TABLE, [
            'operation_uuid' => '81000000-0000-4000-8000-000000000004',
            'idempotency_key' => 'ysh-refund-000000000000000000000000001',
            'scope_key' => YSHelcimOperationScope::fromBusinessKey('refund-order:48'),
            'active_scope_key' => YSHelcimOperationScope::fromBusinessKey('refund-order:48'),
            'operation_type' => 'refund',
            'gateway' => 'ys_helcim',
            'order_id' => 48,
            'transaction_id' => 60,
            'amount' => 123,
            'currency' => 'USD',
            'payment_mode' => 'test',
            'remote_status' => 'processing',
            'local_status' => 'pending',
            'vendor_transaction_id' => null,
            'created_at' => '2026-09-23 02:59:00',
            'updated_at' => '2026-09-23 02:59:00',
        ]);
        $before = $this->database->allRows();

        $result = $this->sync()->recordProof($this->proof(), $this->bindings());
        $webhook = $this->sync()->reconcileWebhook($this->proof(), '85267563', $this->bindings());

        self::assertSame('retry', $result['status']);
        self::assertSame('refund_in_progress', $result['reason']);
        self::assertSame(503, $webhook['code']);
        self::assertSame($before, $this->database->allRows());
        self::assertSame([], $this->recorderCalls);
    }

    public function testAMissingHelcimUserStillRecords(): void
    {
        $proof = $this->proof();
        unset($proof['user']);

        $result = $this->sync()->recordProof($proof, $this->bindings());

        self::assertSame('recorded', $result['status']);
        self::assertStringContainsString('Helcim user: unknown', $this->notes[0][2]);
    }

    public function testMarkupInTheHelcimUserFieldNeverReachesTheOrderNote(): void
    {
        $result = $this->sync()->recordProof(
            $this->proof(['user' => '<img src=x onerror=alert(1)>', 'dateCreated' => '<b>now</b>']),
            $this->bindings()
        );

        self::assertSame('recorded', $result['status']);
        self::assertStringNotContainsString('<', $this->notes[0][2]);
        self::assertStringContainsString('Helcim user: unknown, -', $this->notes[0][2]);
    }

    public function testLocalAccountingDriftNeedsReviewAndPersistsTheLocalFailure(): void
    {
        $this->nextRecorderError = new \WP_Error('ys_helcim_local_accounting_drift', 'Drift.');

        $result = $this->sync()->recordProof($this->proof(), $this->bindings());

        self::assertSame('review', $result['status']);
        self::assertSame('local_recording_failed', $result['reason']);
        self::assertCount(1, $this->localFailures);
        self::assertSame($result['operation_uuid'], $this->localFailures[0][0]);
        self::assertSame('ys_helcim_local_accounting_drift', $this->localFailures[0][1]);
        self::assertSame([], $this->notes);
    }

    public function testATemporaryLocalFailureIsRetryableAndTheNextDeliveryResumesTheSameOperation(): void
    {
        $sync = $this->sync();
        $this->nextRecorderError = new \WP_Error('ys_helcim_local_storage_unavailable', 'Temporarily unavailable.');

        $first = $sync->recordProof($this->proof(), $this->bindings());
        $second = $sync->recordProof($this->proof(), $this->bindings());

        self::assertSame('retry', $first['status']);
        self::assertSame('local_recording_unavailable', $first['reason']);
        self::assertSame('recorded', $second['status']);
        self::assertSame($first['operation_uuid'], $second['operation_uuid']);
        self::assertCount(2, $this->database->allRows());
        self::assertSame([$first['operation_uuid'], $first['operation_uuid']], $this->recorderCalls);
        self::assertCount(1, $this->notes);
    }

    public function testAReplayedLocalRecordingNeverWritesASecondOrderNote(): void
    {
        // 第一次本地寫入其實已 commit 但回應遺失；重送時 recorder 回 replayed=true，不得再寫一次備註。
        $sync = new YSHelcimProviderRefundSync(
            $this->operations,
            fn (): array => ['transaction' => $this->sourceTransaction, 'order' => $this->order, 'refunds' => $this->refunds],
            function (string $uuid): array {
                $this->recorderCalls[] = $uuid;
                return ['operation_uuid' => $uuid, 'local_transaction_id' => 61, 'local_status' => 'recorded', 'replayed' => true];
            },
            null,
            null,
            function (int $orderId, string $title, string $message): void {
                $this->notes[] = [$orderId, $title, $message];
            },
            static fn (): string => 'api-token-test',
            fn (): array => ['data' => $this->family],
            static function (): void {
            }
        );

        $result = $sync->recordProof($this->proof(), $this->bindings());

        self::assertSame('recorded', $result['status']);
        self::assertCount(1, $this->recorderCalls);
        self::assertSame([], $this->notes);
    }

    public function testWebhookResultsUseTheHandlerContract(): void
    {
        $sync = $this->sync();

        self::assertSame(['code' => 400, 'message' => 'transaction proof mismatch'], $sync->reconcileWebhook($this->proof(), '85267564', $this->bindings()));
        self::assertSame(['code' => 200, 'message' => 'ignored'], $sync->reconcileWebhook($this->proof(['status' => 'DECLINED']), '85267563', $this->bindings()));
        self::assertSame(['code' => 200, 'message' => 'provider refund recorded'], $sync->reconcileWebhook($this->proof(), '85267563', $this->bindings()));
        self::assertSame(['code' => 200, 'message' => 'provider refund already recorded'], $sync->reconcileWebhook($this->proof(), '85267563', $this->bindings()));
        $review = $sync->reconcileWebhook($this->proof(['transactionId' => '85300002', 'type' => 'refund', 'amount' => '9.99']), '85300002', $this->bindings());
        self::assertSame(409, $review['code']);
        self::assertSame('provider refund requires review: amount_exceeds_remaining', $review['message']);
    }

    public function testManualSyncRecordsOnlyRefundRecordsOfTheListedPurchase(): void
    {
        $listed = [];
        $sync = $this->sync(
            static function (string $gateway, string $mode): string {
                return 'ys_helcim' === $gateway && 'test' === $mode ? 'api-token-test' : '';
            },
            function (string $invoice, string $token) use (&$listed): array {
                $listed[] = [$invoice, $token];
                // 同 invoice 的退款紀錄（不論狀態）會讓作廢送人工檢查（見 R2 的測試），
                // 所以這裡用被拒絕的「作廢」嘗試來驗證未核准紀錄只會被略過。
                return ['data' => [
                    $this->proof(['transactionId' => '82553743', 'type' => 'purchase']),
                    $this->proof(['transactionId' => '85267570', 'type' => 'reverse', 'status' => 'DECLINED']),
                    $this->proof(['transactionId' => '85267580', 'type' => 'refund', 'invoiceNumber' => '00000000-0000-4000-8000-000000000077']),
                    $this->proof(),
                ]];
            }
        );

        $summary = $sync->syncOrder(48);

        self::assertIsArray($summary);
        self::assertSame([[self::PURCHASE_UUID, 'api-token-test']], $listed);
        self::assertSame('recorded', $summary['status']);
        self::assertSame(1, $summary['helcim_payments']);
        self::assertSame([['transaction_id' => '85267563', 'provider_action' => 'reverse', 'reason' => 'recorded']], $summary['recorded']);
        self::assertSame([
            ['transaction_id' => '85267570', 'provider_action' => 'reverse', 'reason' => 'not_approved'],
            ['transaction_id' => '85267580', 'provider_action' => 'refund', 'reason' => 'invoice_mismatch'],
        ], $summary['skipped']);
        self::assertSame([], $summary['already_recorded']);
        self::assertSame([], $summary['retry']);
        self::assertSame([], $summary['review']);

        $again = $sync->syncOrder(48);
        self::assertSame('nothing_new', $again['status']);
        self::assertSame([['transaction_id' => '85267563', 'provider_action' => 'reverse', 'reason' => 'already_recorded']], $again['already_recorded']);
        self::assertCount(1, $this->recorderCalls);
    }

    public function testManualSyncCredentialOrLookupFailureMeansRetryLater(): void
    {
        $noCredential = $this->sync(
            static fn (): \WP_Error => new \WP_Error('ys_helcim_refund_credentials_unavailable', 'No token.'),
            static fn (): array => []
        );
        $lookupFailed = $this->sync(
            static fn (): string => 'api-token-test',
            static fn (): \WP_Error => new \WP_Error('transport', 'timeout api-token-test')
        );
        $garbage = $this->sync(
            static fn (): string => 'api-token-test',
            static fn (): array => ['data' => ['transactionId' => '1']]
        );

        foreach ([[$noCredential, 'credential_unavailable'], [$lookupFailed, 'provider_lookup_failed'], [$garbage, 'provider_lookup_failed']] as [$sync, $reason]) {
            $summary = $sync->syncOrder(48);
            self::assertSame('retry_later', $summary['status']);
            self::assertSame([['transaction_id' => '', 'provider_action' => '', 'reason' => $reason]], $summary['retry']);
            self::assertStringNotContainsString('api-token-test', (string) json_encode($summary));
        }
        self::assertSame([], $this->recorderCalls);
    }

    public function testManualSyncWithoutAnyAppliedHelcimPaymentHasNothingToCheck(): void
    {
        $lookups = 0;
        $sync = $this->sync(
            static fn (): string => 'api-token-test',
            static function () use (&$lookups): array {
                ++$lookups;
                return [];
            }
        );

        $summary = $sync->syncOrder(47);
        $invalid = $sync->syncOrder(0);

        self::assertSame('nothing_new', $summary['status']);
        self::assertSame(0, $summary['helcim_payments']);
        self::assertSame(0, $lookups);
        self::assertInstanceOf(\WP_Error::class, $invalid);
        self::assertSame(422, $invalid->get_error_data()['status']);
    }

    public function testTheCreatedOperationIsAcceptedByTheRealLocalRecorder(): void
    {
        // 用本服務產生的作業列，交給真正的 YSHelcimLocalRefundRecorder：指紋、payload、範圍鎖都要被接受。
        $captured = null;
        $sync = new YSHelcimProviderRefundSync(
            $this->operations,
            fn (): array => ['transaction' => $this->sourceTransaction, 'order' => $this->order, 'refunds' => $this->refunds],
            function (string $uuid) use (&$captured): array {
                $captured = $this->operations->findByUuid($uuid);
                return ['operation_uuid' => $uuid, 'local_transaction_id' => 61, 'local_status' => 'recorded', 'replayed' => false];
            },
            null,
            null,
            null,
            static fn (): string => 'api-token-test',
            fn (): array => ['data' => $this->family],
            static function (): void {
            }
        );
        self::assertSame('recorded', $sync->recordProof($this->proof(), $this->bindings())['status']);
        self::assertIsArray($captured);

        $local = $this->seededLocalRefundDatabase();
        $local->seed('wp_ys_helcim_operations', $captured);

        $recorded = (new YSHelcimLocalRefundRecorder($local, static fn (): string => '2026-09-23 03:00:00'))->record($captured['operation_uuid']);

        self::assertIsArray($recorded, $recorded instanceof \WP_Error ? $recorded->get_error_code() : '');
        self::assertSame('recorded', $recorded['local_status']);
        $refunds = array_values(array_filter(
            $local->rows('wp_fct_order_transactions'),
            static fn (array $row): bool => 'refund' === ($row['transaction_type'] ?? null)
        ));
        self::assertCount(1, $refunds);
        self::assertSame('85267563', $refunds[0]['vendor_charge_id']);
        self::assertSame(123, $refunds[0]['total']);
        $meta = json_decode($refunds[0]['meta'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('reverse', $meta['ys_helcim_provider_action']);
        self::assertSame(self::PURCHASE_UUID, $meta['ys_helcim_root_refund_uuid']);
        self::assertSame([], $meta['item_ids']);
        self::assertSame(100, $meta['ys_helcim_item_allocated_amount']);
        self::assertSame(23, $meta['ys_helcim_unallocated_amount']);
        self::assertSame('Voided in Helcim (Helcim transaction 85267563).', $meta['reason']);
        $orders = $local->rows('wp_fct_orders');
        self::assertSame(123, $orders[0]['total_refund']);
        self::assertSame('refunded', $orders[0]['payment_status']);
        self::assertSame(100, $local->rows('wp_fct_order_items')[0]['refund_total']);
    }

    public function testTheRealRecorderAndFinalizerReleaseTheRefundScopeOfAHelcimSideVoid(): void
    {
        // 不靠測試 stub 自己寫回：本服務建立的外部作廢列交給正式的 coordinator 組合
        // （真的 YSHelcimLocalRefundRecorder → 收尾工作 → 真的 YSHelcimRefundFinalizer），
        // 由正式程式把 local_status 推到 applied，並在那一刻釋放 refund-order 範圍鎖。
        $clock = static fn (): string => '2026-09-23 03:00:00';
        $local = $this->seededLocalRefundDatabase();
        $finalizer = new YSHelcimRefundFinalizer(
            new YSHelcimOperationRepository($local, $clock),
            new YSHelcimOutboxRepository($local, $clock, static fn (): string => '00000000-0000-4000-8000-0000000000aa')
        );
        $observedWhileEffectsPending = [];
        $coordinator = new YSHelcimRefundLocalCoordinator(
            [new YSHelcimLocalRefundRecorder($local, $clock), 'record'],
            // 收尾工作（客戶統計、退款 hook）會呼叫 FluentCart，這裡只把下一筆 pending 標成完成；
            // 作業列的 local_status 與範圍鎖完全由真的記錄器與 finalizer 決定。
            function (string $uuid) use ($local, &$observedWhileEffectsPending): ?array {
                $operation = $this->localOperation($local, $uuid);
                $observedWhileEffectsPending[] = [$operation['local_status'], $operation['active_scope_key']];
                foreach ($local->rows('wp_ys_helcim_outbox') as $effect) {
                    if ($effect['operation_uuid'] === $uuid && 'pending' === $effect['status']) {
                        $local->update(
                            'wp_ys_helcim_outbox',
                            ['status' => 'completed', 'completed_at' => '2026-09-23 03:00:00', 'updated_at' => '2026-09-23 03:00:00'],
                            ['id' => $effect['id']]
                        );
                        return ['effect_type' => $effect['effect_type'], 'status' => 'completed'];
                    }
                }
                return null;
            },
            [$finalizer, 'finalize'],
            static fn (): bool => true
        );
        $sync = new YSHelcimProviderRefundSync(
            $this->operations,
            fn (): array => ['transaction' => $this->sourceTransaction, 'order' => $this->order, 'refunds' => $this->refunds],
            function (string $uuid) use ($local, $coordinator) {
                // 正式環境是同一個資料庫；測試把本服務剛建立的作業列原樣交給記錄器使用的資料庫。
                $local->seed('wp_ys_helcim_operations', $this->operations->findByUuid($uuid));
                return $coordinator->record($uuid);
            },
            null,
            null,
            null,
            static fn (): string => 'api-token-test',
            fn (): array => ['data' => $this->family],
            static function (): void {
            }
        );
        $scope = YSHelcimOperationScope::fromBusinessKey('refund-order:48');

        $result = $sync->recordProof($this->proof(), $this->bindings());

        self::assertSame('recorded', $result['status'], $result['reason']);
        $operation = $this->localOperation($local, $result['operation_uuid']);
        self::assertSame('reverse', $operation['operation_type']);
        self::assertSame($scope, $operation['scope_key']);
        self::assertSame('applied', $operation['local_status']);
        self::assertNull($operation['active_scope_key'], 'The real finalizer must release the refund-order scope once the row is applied.');
        self::assertSame('2026-09-23 03:00:00', $operation['local_applied_at']);
        self::assertSame(['recorded', 'applied'], array_slice($local->journalStatusTransitions, -2));
        // 收尾工作完成前，記錄器已寫成 recorded 但鎖仍在：釋放確實發生在 finalizer 把列推到 applied 時。
        self::assertCount(2, $observedWhileEffectsPending);
        foreach ($observedWhileEffectsPending as [$localStatus, $activeScope]) {
            self::assertSame('recorded', $localStatus);
            self::assertSame($scope, $activeScope);
        }
        $effects = [];
        foreach ($local->rows('wp_ys_helcim_outbox') as $effect) {
            $effects[$effect['effect_type']] = $effect['status'];
        }
        self::assertSame(['stock_restore' => 'skipped', 'customer_recount' => 'completed', 'refund_hooks' => 'completed'], $effects);
        $refunds = array_values(array_filter(
            $local->rows('wp_fct_order_transactions'),
            static fn (array $row): bool => 'refund' === ($row['transaction_type'] ?? null)
        ));
        self::assertCount(1, $refunds);
        self::assertSame('85267563', $refunds[0]['vendor_charge_id']);
    }

    /** @return array<string, array{array<string,string>}> */
    public static function invalidPurchaseOperations(): array
    {
        return [
            'operation UUID is not a UUID' => [['operation_uuid' => 'not-a-uuid']],
            'gateway is not Helcim' => [['gateway' => 'stripe']],
            'payment mode is unknown' => [['payment_mode' => 'sandbox']],
        ];
    }

    /** @param array<string,string> $change */
    #[DataProvider('invalidPurchaseOperations')]
    public function testManualSyncFlagsAnInvalidPurchaseOperationWithoutAskingHelcim(array $change): void
    {
        // 作業列本身不合法時，不拿任何帳號去問 Helcim，也不建列：只回報需要人工檢查。
        $this->database->update(self::TABLE, $change, ['operation_uuid' => self::PURCHASE_UUID]);
        $credentialCalls = 0;
        $lookups = 0;
        $sync = $this->sync(
            static function () use (&$credentialCalls): string {
                ++$credentialCalls;
                return 'api-token-test';
            },
            function () use (&$lookups): array {
                ++$lookups;
                return ['data' => [$this->proof()]];
            }
        );
        $before = $this->database->allRows();

        $summary = $sync->syncOrder(48);

        self::assertSame('needs_review', $summary['status']);
        self::assertSame(1, $summary['helcim_payments']);
        self::assertSame([['transaction_id' => '', 'provider_action' => '', 'reason' => 'invalid_purchase_operation']], $summary['review']);
        self::assertSame([], $summary['recorded']);
        self::assertSame(0, $credentialCalls);
        self::assertSame(0, $lookups);
        self::assertSame([], $this->recorderCalls);
        self::assertSame($before, $this->database->allRows());
    }

    private function seededLocalRefundDatabase(): LocalRefundWpdb
    {
        $local = new LocalRefundWpdb();
        $local->seed('wp_fct_order_transactions', $this->sourceTransaction + [
            'order_type' => 'payment',
            'subscription_id' => null,
            'card_last_4' => 4242,
            'card_brand' => 'visa',
            'payment_method_type' => 'card',
            'rate' => '1.0000',
        ]);
        $local->seed('wp_fct_orders', $this->order + [
            'type' => 'payment',
            'customer_id' => 5,
            'uuid' => 'fc-order-48',
            'payment_status' => 'paid',
            'refunded_at' => null,
        ]);
        // 品項只有 1.00，另外 0.23 是不在品項上的金額（例如運費）：無品項 payload＝全部品項按比例，其餘走訂單層。
        $local->seed('wp_fct_order_items', [
            'id' => 81,
            'order_id' => 48,
            'post_id' => 45,
            'object_id' => 45,
            'quantity' => 1,
            'fulfillment_type' => 'digital',
            'payment_type' => 'onetime',
            'post_title' => 'HELCIM INLINE TEST',
            'title' => 'USD 1.23',
            'unit_price' => 100,
            'subtotal' => 100,
            'tax_amount' => 0,
            'shipping_charge' => 0,
            'discount_total' => 0,
            'rate' => '1.0000',
            'fulfilled_quantity' => 0,
            'line_total' => 100,
            'refund_total' => 0,
        ]);

        return $local;
    }

    /** @return array<string,mixed> */
    private function localOperation(LocalRefundWpdb $local, string $uuid): array
    {
        foreach ($local->rows('wp_ys_helcim_operations') as $row) {
            if (($row['operation_uuid'] ?? null) === $uuid) {
                return $row;
            }
        }

        self::fail('Missing local operation ' . $uuid);
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function proof(array $overrides = []): array
    {
        $proof = array_merge([
            'transactionId' => '85267563',
            'dateCreated' => '2026-09-22 10:31:05',
            'cardBatchId' => '4410001',
            'status' => 'APPROVED',
            'user' => 'store-admin',
            'type' => 'reverse',
            'amount' => 1.23,
            'currency' => 'USD',
            'invoiceNumber' => self::PURCHASE_UUID,
        ], $overrides);

        return array_filter($proof, static fn (mixed $value): bool => null !== $value);
    }

    /** @return array<int,array{gateway:string,mode:string}> */
    private function bindings(): array
    {
        return [['gateway' => 'ys_helcim', 'mode' => 'test']];
    }

    private function sync(?callable $credentials = null, ?callable $lister = null): YSHelcimProviderRefundSync
    {
        return new YSHelcimProviderRefundSync(
            $this->operations,
            fn (int $orderId, int $transactionId): ?array => 48 === $orderId && 60 === $transactionId
                ? ['transaction' => $this->sourceTransaction, 'order' => $this->order, 'refunds' => $this->refunds]
                : null,
            function (string $uuid) {
                $this->recorderCalls[] = $uuid;
                if (null !== $this->nextRecorderError) {
                    $error = $this->nextRecorderError;
                    $this->nextRecorderError = null;
                    return $error;
                }
                // 模擬既有 coordinator：本地寫入完成後 applied 並釋放退款範圍鎖。
                $this->database->update(
                    self::TABLE,
                    ['local_status' => 'applied', 'active_scope_key' => null, 'local_transaction_id' => 61, 'resolved_at' => '2026-09-23 03:00:00'],
                    ['operation_uuid' => $uuid]
                );
                return ['operation_uuid' => $uuid, 'local_transaction_id' => 61, 'local_status' => 'applied', 'replayed' => false];
            },
            function (string $uuid, string $code, string $message): bool {
                $this->localFailures[] = [$uuid, $code, $message];
                return true;
            },
            static fn (): int => 0,
            function (int $orderId, string $title, string $message): void {
                $this->notes[] = [$orderId, $title, $message];
            },
            $credentials ?? static fn (): string => 'api-token-test',
            $lister ?? function (string $invoice, string $token): array {
                ++$this->familyLookups;
                return $invoice === self::PURCHASE_UUID && $token === 'api-token-test' ? ['data' => $this->family] : ['data' => []];
            },
            function (string $message, array $context): void {
                $this->reviews[] = [$message, $context];
            }
        );
    }

    /** @return array<string,mixed> */
    private function rowByVendor(string $vendorId): array
    {
        foreach ($this->database->allRows() as $row) {
            if (($row['vendor_transaction_id'] ?? null) === $vendorId) {
                return $row;
            }
        }

        self::fail('Missing operation for provider transaction ' . $vendorId);
    }

    /** @return array<string,mixed>|null */
    private function activeRefundScopeRow(): ?array
    {
        $scope = YSHelcimOperationScope::fromBusinessKey('refund-order:48');
        foreach ($this->database->allRows() as $row) {
            if (($row['active_scope_key'] ?? null) === $scope) {
                return $row;
            }
        }

        return null;
    }
}
