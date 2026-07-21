<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Integration\Operations;

use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimIdempotency;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationRepository;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOutboxRepository;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundFinalizer;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundPayload;
use YangSheep\Helcim\FluentCart\Tests\Doubles\FakeOutboxWpdb;
use YangSheep\Helcim\FluentCart\Tests\Doubles\FakeWpdb;

final class RefundFinalizerTest extends TestCase
{
    private const OPERATION_UUID = '00000000-0000-4000-8000-000000000001';

    private FakeWpdb $operationDatabase;
    private YSHelcimOperationRepository $operations;
    private YSHelcimOutboxRepository $outbox;
    private YSHelcimRefundFinalizer $finalizer;

    protected function setUp(): void
    {
        $this->operationDatabase = new FakeWpdb();
        $this->operations = new YSHelcimOperationRepository(
            $this->operationDatabase,
            static fn (): string => '2026-07-21 00:00:00'
        );
        $this->outbox = new YSHelcimOutboxRepository(
            new FakeOutboxWpdb(),
            static fn (): string => '2026-07-21 00:00:00',
            static fn (): string => '00000000-0000-4000-8000-000000000099'
        );
        $this->finalizer = new YSHelcimRefundFinalizer($this->operations, $this->outbox);

        $this->recordedOperation();
    }

    public function testPendingEffectsKeepTheRefundRecordedAndScopeLocked(): void
    {
        $this->enqueuePlan(true);

        $result = $this->finalizer->finalize(self::OPERATION_UUID);

        self::assertIsArray($result);
        self::assertSame('waiting', $result['status']);
        self::assertSame('recorded', $this->storedOperation()['local_status']);
        self::assertNotNull($this->storedOperation()['active_scope_key']);
    }

    public function testSuccessfulOrSkippedEffectsApplyTheRefundAndReleaseItsScope(): void
    {
        $this->enqueuePlan(false);
        $this->completeNext('customer_recount');
        $this->completeNext('refund_hooks');

        $result = $this->finalizer->finalize(self::OPERATION_UUID);

        self::assertIsArray($result);
        self::assertSame('applied', $result['status']);
        self::assertSame([], $result['warnings']);
        self::assertSame('applied', $this->storedOperation()['local_status']);
        self::assertNull($this->storedOperation()['active_scope_key']);

        $replay = $this->finalizer->finalize(self::OPERATION_UUID);
        self::assertSame('applied', $replay['status']);
        self::assertTrue($replay['replayed']);
    }

    public function testInspectionReportsTerminalEffectsWithoutMutatingTheRecordedOperation(): void
    {
        $this->enqueuePlan(false);
        $this->completeNext('customer_recount');
        $this->completeNext('refund_hooks');

        $result = $this->finalizer->inspect(self::OPERATION_UUID);

        self::assertIsArray($result);
        self::assertSame('ready_to_apply', $result['status']);
        self::assertSame('recorded', $result['local_status']);
        self::assertSame('delivered', $result['notification_status']);
        self::assertSame('recorded', $this->storedOperation()['local_status']);
        self::assertNotNull($this->storedOperation()['active_scope_key']);
    }

    public function testTerminalNonStockFailuresReleaseScopeButRemainVisibleAsWarnings(): void
    {
        $this->enqueuePlan(true);
        $this->completeNext('stock_restore');
        $this->failNext('customer_recount', false);
        $this->failNext('refund_hooks', false);

        $result = $this->finalizer->finalize(self::OPERATION_UUID);

        self::assertIsArray($result);
        self::assertSame('applied_with_warnings', $result['status']);
        self::assertSame(['customer_recount', 'refund_hooks'], $result['warnings']);
        self::assertSame('applied', $this->storedOperation()['local_status']);
        self::assertNull($this->storedOperation()['active_scope_key']);
    }

    public function testAmbiguousStockRestorationKeepsTheRefundRecordedAndScopeLocked(): void
    {
        $this->enqueuePlan(true);
        $this->failNext('stock_restore', false);
        $this->completeNext('customer_recount');
        $this->completeNext('refund_hooks');

        $result = $this->finalizer->finalize(self::OPERATION_UUID);

        self::assertIsArray($result);
        self::assertSame('stock_reconciliation_required', $result['status']);
        self::assertSame(['stock_restore'], $result['warnings']);
        self::assertSame('recorded', $this->storedOperation()['local_status']);
        self::assertNotNull($this->storedOperation()['active_scope_key']);
    }

    public function testAmbiguousStockStopsBeforeLaterPendingEffectsAreTreatedAsRetryableWork(): void
    {
        $this->enqueuePlan(true);
        $this->failNext('stock_restore', false);

        $result = $this->finalizer->finalize(self::OPERATION_UUID);

        self::assertIsArray($result);
        self::assertSame('stock_reconciliation_required', $result['status']);
        self::assertSame('pending', $result['effect_statuses']['customer_recount']);
        self::assertSame('pending', $result['effect_statuses']['refund_hooks']);
        self::assertSame('recorded', $this->storedOperation()['local_status']);
        self::assertNotNull($this->storedOperation()['active_scope_key']);
    }

    public function testAnIncompleteOrMutatedEffectPlanFailsClosed(): void
    {
        $this->outbox->enqueue(
            self::OPERATION_UUID,
            'stock_restore',
            YSHelcimOutboxRepository::CLASS_AT_MOST_ONCE,
            10,
            ['operation_uuid' => self::OPERATION_UUID],
            YSHelcimOutboxRepository::STATUS_SKIPPED
        );

        $result = $this->finalizer->finalize(self::OPERATION_UUID);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_effect_plan_conflict', $result->get_error_code());
        self::assertSame('recorded', $this->storedOperation()['local_status']);
        self::assertNotNull($this->storedOperation()['active_scope_key']);
    }

    private function recordedOperation(): void
    {
        $transactionUuid = 'fc-transaction-123';
        $payload = YSHelcimRefundPayload::normalize([]);
        $operation = [
            'operation_uuid' => self::OPERATION_UUID,
            'idempotency_key' => YSHelcimIdempotency::generate(
                'refund',
                $transactionUuid,
                2100,
                'test',
                self::OPERATION_UUID
            ),
            'scope_key' => 'refund-order:10',
            'operation_type' => 'refund',
            'gateway' => 'ys_helcim',
            'order_id' => 10,
            'transaction_id' => 20,
            'transaction_uuid' => $transactionUuid,
            'amount' => 2100,
            'currency' => 'USD',
            'payment_mode' => 'test',
            'provider_correlation_id' => 'corr-finalizer-1',
            'request_fingerprint' => hash('sha256', 'request-finalizer-1'),
            'local_payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'local_payload_hash' => YSHelcimRefundPayload::hash($payload),
            'source_vendor_transaction_id' => '51177061',
        ];

        self::assertIsArray($this->operations->create($operation));
        self::assertTrue($this->operations->claimRemoteProcessing(self::OPERATION_UUID));
        self::assertTrue($this->operations->transitionRemote(
            self::OPERATION_UUID,
            'processing',
            'succeeded',
            ['vendor_transaction_id' => '51177123']
        ));
        self::assertTrue($this->operations->claimLocalApplying(self::OPERATION_UUID, 'pending'));
        self::assertTrue($this->operations->transitionLocal(
            self::OPERATION_UUID,
            'applying',
            'recorded',
            ['local_transaction_id' => 22]
        ));
    }

    private function enqueuePlan(bool $manageStock): void
    {
        foreach ([
            ['stock_restore', YSHelcimOutboxRepository::CLASS_AT_MOST_ONCE, 10, $manageStock ? 'pending' : 'skipped'],
            ['customer_recount', YSHelcimOutboxRepository::CLASS_IDEMPOTENT, 20, 'pending'],
            ['refund_hooks', YSHelcimOutboxRepository::CLASS_AT_MOST_ONCE, 30, 'pending'],
        ] as [$type, $class, $sequence, $status]) {
            self::assertIsArray($this->outbox->enqueue(
                self::OPERATION_UUID,
                $type,
                $class,
                $sequence,
                ['operation_uuid' => self::OPERATION_UUID, 'effect' => $type],
                $status
            ));
        }
    }

    private function completeNext(string $expectedType): void
    {
        $effect = $this->outbox->claimNext(self::OPERATION_UUID);
        self::assertIsArray($effect);
        self::assertSame($expectedType, $effect['effect_type']);
        self::assertTrue($this->outbox->complete((int) $effect['id'], (string) $effect['claim_token']));
    }

    private function failNext(string $expectedType, bool $retryable): void
    {
        $effect = $this->outbox->claimNext(self::OPERATION_UUID);
        self::assertIsArray($effect);
        self::assertSame($expectedType, $effect['effect_type']);
        self::assertTrue($this->outbox->fail(
            (int) $effect['id'],
            (string) $effect['claim_token'],
            'effect_failed',
            'Effect failed.',
            $retryable
        ));
    }

    /** @return array<string,mixed> */
    private function storedOperation(): array
    {
        $operation = $this->operations->findByUuid(self::OPERATION_UUID);
        self::assertIsArray($operation);
        return $operation;
    }
}
