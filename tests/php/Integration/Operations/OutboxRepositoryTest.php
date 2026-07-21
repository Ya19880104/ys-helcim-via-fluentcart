<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Integration\Operations;

use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOutboxRepository;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOutboxWorker;
use YangSheep\Helcim\FluentCart\Tests\Doubles\FakeOutboxWpdb;

final class OutboxRepositoryTest extends TestCase
{
    private FakeOutboxWpdb $database;
    private YSHelcimOutboxRepository $repository;
    private string $operation = '00000000-0000-4000-8000-000000000001';

    protected function setUp(): void
    {
        unset(\YSHelcimWpDouble::$options['ys_helcim_outbox_recovery_cursor_v1']);
        $this->database = new FakeOutboxWpdb();
        $this->repository = new YSHelcimOutboxRepository(
            $this->database,
            static fn (): string => '2026-07-21 00:00:00',
            static fn (): string => '00000000-0000-4000-8000-000000000099'
        );
    }

    public function testExactReplayReusesEffectButChangedPayloadConflicts(): void
    {
        $first = $this->repository->enqueue(
            $this->operation,
            'customer_recount',
            YSHelcimOutboxRepository::CLASS_IDEMPOTENT,
            20,
            ['order_id' => 10]
        );
        $replay = $this->repository->enqueue(
            $this->operation,
            'customer_recount',
            YSHelcimOutboxRepository::CLASS_IDEMPOTENT,
            20,
            ['order_id' => 10]
        );
        $changed = $this->repository->enqueue(
            $this->operation,
            'customer_recount',
            YSHelcimOutboxRepository::CLASS_IDEMPOTENT,
            20,
            ['order_id' => 11]
        );

        self::assertIsArray($first);
        self::assertSame($first['id'], $replay['id']);
        self::assertInstanceOf(\WP_Error::class, $changed);
        self::assertCount(1, $this->database->allRows());
    }

    public function testReplayCannotChangeEffectClassSequenceOrInitialStatus(): void
    {
        $first = $this->repository->enqueue(
            $this->operation,
            'customer_recount',
            YSHelcimOutboxRepository::CLASS_IDEMPOTENT,
            20,
            ['order_id' => 10]
        );

        foreach ([
            [YSHelcimOutboxRepository::CLASS_AT_MOST_ONCE, 20, YSHelcimOutboxRepository::STATUS_PENDING],
            [YSHelcimOutboxRepository::CLASS_IDEMPOTENT, 21, YSHelcimOutboxRepository::STATUS_PENDING],
            [YSHelcimOutboxRepository::CLASS_IDEMPOTENT, 20, YSHelcimOutboxRepository::STATUS_SKIPPED],
        ] as [$class, $sequence, $status]) {
            $result = $this->repository->enqueue(
                $this->operation,
                'customer_recount',
                $class,
                $sequence,
                ['order_id' => 10],
                $status
            );
            self::assertInstanceOf(\WP_Error::class, $result);
        }

        self::assertIsArray($first);
        self::assertCount(1, $this->database->allRows());
    }

    public function testEffectsAreClaimedInSequenceAndOnlyOnce(): void
    {
        $this->enqueue('hook_order_refunded', YSHelcimOutboxRepository::CLASS_AT_MOST_ONCE, 30);
        $this->enqueue('customer_recount', YSHelcimOutboxRepository::CLASS_IDEMPOTENT, 20);

        $first = $this->repository->claimNext($this->operation);
        self::assertSame('customer_recount', $first['effect_type']);
        self::assertNull($this->repository->claimNext($this->operation));
        self::assertTrue($this->repository->complete((int) $first['id'], $first['claim_token'], ['ok' => true]));

        $second = $this->repository->claimNext($this->operation);
        self::assertSame('hook_order_refunded', $second['effect_type']);
    }

    public function testAmbiguousExternalHookIsNeverAutomaticallyRetried(): void
    {
        $this->enqueue('hook_order_refunded', YSHelcimOutboxRepository::CLASS_AT_MOST_ONCE, 30);
        $worker = new YSHelcimOutboxWorker($this->repository, [
            'hook_order_refunded' => static function (): void {
                throw new \RuntimeException('crash during hook');
            },
        ]);

        $worker->runOnce($this->operation);

        self::assertSame('indeterminate', $this->repository->find($this->operation, 'hook_order_refunded')['status']);
        self::assertNull($worker->runOnce($this->operation));
    }

    public function testStaleSafeClaimReturnsToPendingButExternalClaimBecomesIndeterminate(): void
    {
        $safeOperation = $this->operation;
        $externalOperation = '00000000-0000-4000-8000-000000000002';
        $this->enqueue('customer_recount', YSHelcimOutboxRepository::CLASS_IDEMPOTENT, 20, $safeOperation);
        $this->enqueue('hook_order_refunded', YSHelcimOutboxRepository::CLASS_AT_MOST_ONCE, 30, $externalOperation);
        $this->repository->claimNext($safeOperation);
        $this->repository->claimNext($externalOperation);

        self::assertSame([$safeOperation, $externalOperation], $this->repository->recoverStaleOperationUuids('2026-07-21 00:00:00'));
        self::assertSame('pending', $this->repository->find($safeOperation, 'customer_recount')['status']);
        self::assertSame('indeterminate', $this->repository->find($externalOperation, 'hook_order_refunded')['status']);
    }

    public function testRecoverStaleReadErrorFailsClosedWithoutChangingClaims(): void
    {
        $this->enqueue('customer_recount', YSHelcimOutboxRepository::CLASS_IDEMPOTENT, 20);
        self::assertIsArray($this->repository->claimNext($this->operation));
        $this->database->failNextResults = true;

        $result = $this->repository->recoverStaleOperationUuids('2026-07-21 00:00:00');

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_outbox_unavailable', $result->get_error_code());
        self::assertSame('processing', $this->repository->find($this->operation, 'customer_recount')['status']);
    }

    public function testRecoverStaleQuarantinesACorruptEffectWithoutReplayingIt(): void
    {
        $this->enqueue('refund_hooks', YSHelcimOutboxRepository::CLASS_AT_MOST_ONCE, 30);
        self::assertIsArray($this->repository->claimNext($this->operation));
        $this->database->mutateRow($this->operation, 'refund_hooks', ['effect_class' => 'corrupt']);

        $result = $this->repository->recoverStaleOperationUuids('2026-07-21 00:00:00');

        self::assertSame([], $result);
        $row = $this->repository->find($this->operation, 'refund_hooks');
        self::assertSame('indeterminate', $row['status']);
        self::assertSame('ys_helcim_corrupt_stale_claim', $row['last_error_code']);
    }

    public function testRecoveryReturnsOnlyTheExactAtomicallyRecoveredOperationUuids(): void
    {
        $secondOperation = '00000000-0000-4000-8000-000000000002';
        $this->enqueue('refund_hooks', YSHelcimOutboxRepository::CLASS_AT_MOST_ONCE, 30);
        $this->enqueue('refund_hooks', YSHelcimOutboxRepository::CLASS_AT_MOST_ONCE, 30, $secondOperation);
        self::assertIsArray($this->repository->claimNext($this->operation));
        self::assertIsArray($this->repository->claimNext($secondOperation));

        $result = $this->repository->recoverStaleOperationUuids('2026-07-21 00:00:00', 1);

        self::assertSame([$this->operation], $result);
        $candidateQuery = end($this->database->resultQueries);
        self::assertStringNotContainsString('FOR UPDATE', $candidateQuery['query']);
        self::assertStringContainsString('status = %s AND claimed_at <= %s', $candidateQuery['query']);
        self::assertSame([YSHelcimOutboxRepository::STATUS_PROCESSING, '2026-07-21 00:00:00', 0, 10], $candidateQuery['args']);
        $lockedQuery = end($this->database->rowQueries);
        self::assertStringContainsString('LIMIT 1 FOR UPDATE', $lockedQuery['query']);
        self::assertSame([1, YSHelcimOutboxRepository::STATUS_PROCESSING, '2026-07-21 00:00:00'], $lockedQuery['args']);
    }

    public function testPoisonUpdateCannotRollBackOrStarveOtherStaleClaims(): void
    {
        $secondOperation = '00000000-0000-4000-8000-000000000002';
        $this->enqueue('customer_recount', YSHelcimOutboxRepository::CLASS_IDEMPOTENT, 20);
        $this->enqueue('refund_hooks', YSHelcimOutboxRepository::CLASS_AT_MOST_ONCE, 30, $secondOperation);
        self::assertIsArray($this->repository->claimNext($this->operation));
        self::assertIsArray($this->repository->claimNext($secondOperation));
        $this->database->failOnUpdateNumber = 4;

        $result = $this->repository->recoverStaleOperationUuids('2026-07-21 00:00:00');

        self::assertSame([$this->operation], $result);
        self::assertSame('pending', $this->repository->find($this->operation, 'customer_recount')['status']);
        self::assertSame('processing', $this->repository->find($secondOperation, 'refund_hooks')['status']);
    }

    public function testAPoisonedMaximumScanPageCannotMonopolizeEveryLaterSweep(): void
    {
        $operations = [];
        for ($sequence = 1; $sequence <= 501; ++$sequence) {
            $operation = sprintf('00000000-0000-4000-8000-%012d', $sequence);
            $operations[] = $operation;
            $this->enqueue('customer_recount', YSHelcimOutboxRepository::CLASS_IDEMPOTENT, 20, $operation);
            self::assertIsArray($this->repository->claimNext($operation));
        }
        $this->database->permanentlyFailUpdateIds = range(1, 500);

        $firstSweep = $this->repository->recoverStaleOperationUuids('2026-07-21 00:00:00', 50);
        $secondSweep = $this->repository->recoverStaleOperationUuids('2026-07-21 00:00:00', 50);

        self::assertSame([], $firstSweep);
        self::assertSame([$operations[500]], $secondSweep);
        self::assertSame('pending', $this->repository->find($operations[500], 'customer_recount')['status']);
        self::assertSame('processing', $this->repository->find($operations[0], 'customer_recount')['status']);
        $candidateQueries = array_slice($this->database->resultQueries, -2);
        self::assertSame([YSHelcimOutboxRepository::STATUS_PROCESSING, '2026-07-21 00:00:00', 0, 500], $candidateQueries[0]['args']);
        self::assertSame([YSHelcimOutboxRepository::STATUS_PROCESSING, '2026-07-21 00:00:00', 500, 500], $candidateQueries[1]['args']);
    }

    public function testActionableOperationsAreDueDistinctAndStrictlyLimited(): void
    {
        $secondOperation = '00000000-0000-4000-8000-000000000002';
        $thirdOperation = '00000000-0000-4000-8000-000000000003';
        $skippedOperation = '00000000-0000-4000-8000-000000000004';
        $this->enqueuePlan($this->operation);
        $this->enqueuePlan($secondOperation);
        foreach (['stock_restore', 'customer_recount', 'refund_hooks'] as $effect) {
            $this->database->mutateRow($secondOperation, $effect, ['available_at' => '2026-07-21 00:00:01']);
        }
        $this->enqueuePlan($thirdOperation);
        $this->enqueuePlan($skippedOperation);
        $this->database->setOperationState($skippedOperation, 'succeeded', 'applied');

        $result = $this->repository->actionableOperationUuids(2);

        self::assertSame([$this->operation, $thirdOperation], $result);
        $query = end($this->database->resultQueries);
        self::assertStringContainsString('ys_helcim_actionable_operations', $query['query']);
        self::assertStringContainsString('local_status', $query['query']);
        self::assertStringContainsString('LIMIT %d', $query['query']);
        self::assertSame(['2026-07-21 00:00:00', 2], $query['args']);
    }

    public function testActionableOperationReadErrorFailsClosedEvenWhenTheDriverReturnsAnEmptyArray(): void
    {
        $this->enqueue('customer_recount', YSHelcimOutboxRepository::CLASS_IDEMPOTENT, 20);
        $this->database->failNextResults = true;

        $result = $this->repository->actionableOperationUuids();

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_outbox_unavailable', $result->get_error_code());
    }

    public function testActionableOperationUuidValidationFailsClosedOnCorruptStoredIdentity(): void
    {
        $this->database->nextActionableRows = [['operation_uuid' => 'corrupt']];

        $result = $this->repository->actionableOperationUuids();

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_outbox_unavailable', $result->get_error_code());
    }

    public function testActionableScanExcludesManualStockBlocksAndAlreadyAppliedOperations(): void
    {
        $manualOperation = $this->operation;
        $appliedOperation = '00000000-0000-4000-8000-000000000002';
        $healthyOperation = '00000000-0000-4000-8000-000000000003';
        foreach ([$manualOperation, $appliedOperation, $healthyOperation] as $operation) {
            $this->enqueuePlan($operation);
        }
        $this->database->mutateRow($manualOperation, 'stock_restore', ['status' => 'indeterminate']);
        $this->database->setOperationState($appliedOperation, 'succeeded', 'applied');

        self::assertSame([$healthyOperation], $this->repository->actionableOperationUuids(50));
    }

    public function testActionableScanIncludesFinalizeOnlyCrashWindowBeforePendingWork(): void
    {
        $pendingOperation = $this->operation;
        $finalizeOperation = '00000000-0000-4000-8000-000000000002';
        $this->enqueuePlan($pendingOperation);
        $this->enqueuePlan($finalizeOperation);
        for ($i = 0; $i < 3; ++$i) {
            $claim = $this->repository->claimNext($finalizeOperation);
            self::assertIsArray($claim);
            self::assertTrue($this->repository->complete((int) $claim['id'], (string) $claim['claim_token']));
        }

        self::assertSame([$finalizeOperation, $pendingOperation], $this->repository->actionableOperationUuids(50));
    }

    public function testActionableScanIncludesReverseFallbackOperations(): void
    {
        $this->enqueuePlan($this->operation);
        $this->database->setOperationState($this->operation, 'succeeded', 'recorded', 'reverse');

        self::assertSame([$this->operation], $this->repository->actionableOperationUuids(50));
    }

    public function testAllForOperationReturnsOnlyTheRequestedEffectsInExecutionOrder(): void
    {
        $otherOperation = '00000000-0000-4000-8000-000000000002';
        $this->enqueue('refund_hooks', YSHelcimOutboxRepository::CLASS_AT_MOST_ONCE, 30);
        $this->enqueue('stock_restore', YSHelcimOutboxRepository::CLASS_AT_MOST_ONCE, 10);
        $this->enqueue('customer_recount', YSHelcimOutboxRepository::CLASS_IDEMPOTENT, 20);
        $this->enqueue('stock_restore', YSHelcimOutboxRepository::CLASS_AT_MOST_ONCE, 10, $otherOperation);

        $rows = $this->repository->allForOperation($this->operation);

        self::assertIsArray($rows);
        self::assertSame(['stock_restore', 'customer_recount', 'refund_hooks'], array_column($rows, 'effect_type'));
        self::assertSame([$this->operation, $this->operation, $this->operation], array_column($rows, 'operation_uuid'));
    }

    public function testTerminalFailureDoesNotPreventALaterEffectFromBeingClaimed(): void
    {
        $this->enqueue('customer_recount', YSHelcimOutboxRepository::CLASS_IDEMPOTENT, 20);
        $this->enqueue('refund_hooks', YSHelcimOutboxRepository::CLASS_AT_MOST_ONCE, 30);

        $first = $this->repository->claimNext($this->operation);
        self::assertIsArray($first);
        self::assertTrue($this->repository->fail(
            (int) $first['id'],
            (string) $first['claim_token'],
            'recount_failed',
            'Customer recount failed.',
            false
        ));

        $second = $this->repository->claimNext($this->operation);
        self::assertIsArray($second);
        self::assertSame('refund_hooks', $second['effect_type']);
    }

    public function testWorkerFinalizesAfterSuccessAndAfterAClaimBecomesTerminal(): void
    {
        $calls = [];
        $this->enqueue('customer_recount', YSHelcimOutboxRepository::CLASS_IDEMPOTENT, 20);
        $worker = new YSHelcimOutboxWorker(
            $this->repository,
            ['customer_recount' => static fn (): array => ['ok' => true]],
            static function (string $operationUuid) use (&$calls): array {
                $calls[] = $operationUuid;
                return ['status' => 'applied'];
            }
        );

        $result = $worker->runOnce($this->operation);

        self::assertIsArray($result);
        self::assertSame(['status' => 'applied'], $result['finalization']);
        self::assertSame([$this->operation], $calls);
        self::assertSame('completed', $this->repository->find($this->operation, 'customer_recount')['status']);
    }

    public function testWorkerAlsoFinalizesWhenThereIsNoPendingEffect(): void
    {
        $calls = 0;
        $worker = new YSHelcimOutboxWorker(
            $this->repository,
            [],
            static function () use (&$calls): array {
                ++$calls;
                return ['status' => 'applied'];
            }
        );

        $result = $worker->runOnce($this->operation);

        self::assertSame(['status' => 'applied'], $result);
        self::assertSame(1, $calls);
    }

    public function testOutboxReadFailureNeverRunsAHandlerOrFinalizer(): void
    {
        $handlerCalls = 0;
        $finalizerCalls = 0;
        $this->enqueue('customer_recount', YSHelcimOutboxRepository::CLASS_IDEMPOTENT, 20);
        $this->database->failNextLookup = true;
        $worker = new YSHelcimOutboxWorker(
            $this->repository,
            ['customer_recount' => static function () use (&$handlerCalls): void { ++$handlerCalls; }],
            static function () use (&$finalizerCalls): void { ++$finalizerCalls; }
        );

        $result = $worker->runOnce($this->operation);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_outbox_unavailable', $result->get_error_code());
        self::assertSame(0, $handlerCalls);
        self::assertSame(0, $finalizerCalls);
    }

    public function testCorruptEffectPayloadNeverRunsAHandlerOrFinalizer(): void
    {
        $handlerCalls = 0;
        $finalizerCalls = 0;
        $this->enqueue('customer_recount', YSHelcimOutboxRepository::CLASS_IDEMPOTENT, 20);
        $this->database->mutateRow($this->operation, 'customer_recount', ['payload_hash' => str_repeat('0', 64)]);
        $worker = new YSHelcimOutboxWorker(
            $this->repository,
            ['customer_recount' => static function () use (&$handlerCalls): void { ++$handlerCalls; }],
            static function () use (&$finalizerCalls): void { ++$finalizerCalls; }
        );

        $result = $worker->runOnce($this->operation);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_effect_payload_invalid', $result->get_error_code());
        self::assertSame(0, $handlerCalls);
        self::assertSame(0, $finalizerCalls);
        self::assertSame('failed', $this->repository->find($this->operation, 'customer_recount')['status']);
    }

    public function testInvalidJsonIsRejectedEvenWhenItsHashMatches(): void
    {
        $handlerCalls = 0;
        $this->enqueue('customer_recount', YSHelcimOutboxRepository::CLASS_IDEMPOTENT, 20);
        $this->database->mutateRow($this->operation, 'customer_recount', [
            'payload' => '{',
            'payload_hash' => hash('sha256', '{'),
        ]);
        $worker = new YSHelcimOutboxWorker(
            $this->repository,
            ['customer_recount' => static function () use (&$handlerCalls): void { ++$handlerCalls; }]
        );

        $result = $worker->runOnce($this->operation);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_effect_payload_invalid', $result->get_error_code());
        self::assertSame(0, $handlerCalls);
    }

    public function testReceiptIntegrityVerifierRunsBeforeAnyEffectHandler(): void
    {
        $handlerCalls = 0;
        $finalizerCalls = 0;
        $verifierCalls = 0;
        $this->enqueue('customer_recount', YSHelcimOutboxRepository::CLASS_IDEMPOTENT, 20);
        $payload = json_encode(['order_id' => 999], JSON_THROW_ON_ERROR);
        $this->database->mutateRow($this->operation, 'customer_recount', [
            'payload' => $payload,
            'payload_hash' => hash('sha256', $payload),
        ]);
        $worker = new YSHelcimOutboxWorker(
            $this->repository,
            ['customer_recount' => static function () use (&$handlerCalls): void { ++$handlerCalls; }],
            static function () use (&$finalizerCalls): void { ++$finalizerCalls; },
            static function (array $effect, array $decoded) use (&$verifierCalls): \WP_Error {
                ++$verifierCalls;
                self::assertSame(999, $decoded['order_id']);
                self::assertSame('customer_recount', $effect['effect_type']);
                return new \WP_Error('ys_helcim_accounting_drift', 'Receipt plan mismatch.');
            }
        );

        $result = $worker->runOnce($this->operation);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_accounting_drift', $result->get_error_code());
        self::assertSame(1, $verifierCalls);
        self::assertSame(0, $handlerCalls);
        self::assertSame(0, $finalizerCalls);
        self::assertSame('failed', $this->repository->find($this->operation, 'customer_recount')['status']);
    }

    private function enqueue(string $type, string $class, int $sequence, ?string $operation = null): void
    {
        $result = $this->repository->enqueue($operation ?? $this->operation, $type, $class, $sequence, ['order_id' => 10]);
        self::assertIsArray($result);
    }

    private function enqueuePlan(string $operation): void
    {
        $this->enqueue('stock_restore', YSHelcimOutboxRepository::CLASS_AT_MOST_ONCE, 10, $operation);
        $this->enqueue('customer_recount', YSHelcimOutboxRepository::CLASS_IDEMPOTENT, 20, $operation);
        $this->enqueue('refund_hooks', YSHelcimOutboxRepository::CLASS_AT_MOST_ONCE, 30, $operation);
    }
}
