<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Refund;

use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundLocalCoordinator;

final class RefundLocalCoordinatorTest extends TestCase
{
    private const OPERATION_UUID = '00000000-0000-4000-8000-000000000001';

    public function testRecorderFailureStopsBeforeAnyEffectWork(): void
    {
        $workerCalls = 0;
        $finalizerCalls = 0;
        $schedulerCalls = 0;
        $coordinator = new YSHelcimRefundLocalCoordinator(
            static fn (): \WP_Error => new \WP_Error('record_failed', 'failed'),
            static function () use (&$workerCalls): void { ++$workerCalls; },
            static function () use (&$finalizerCalls): void { ++$finalizerCalls; },
            static function () use (&$schedulerCalls): bool { ++$schedulerCalls; return true; }
        );

        $result = $coordinator->record(self::OPERATION_UUID);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame(0, $workerCalls);
        self::assertSame(0, $finalizerCalls);
        self::assertSame(0, $schedulerCalls);
    }

    public function testItDrainsAllEffectsAndReturnsAppliedStatus(): void
    {
        $workerCalls = 0;
        $finalizerCalls = 0;
        $scheduled = [];
        $events = [];
        $states = [
            $this->state('waiting', 'recorded', 'pending'),
            $this->state('waiting', 'recorded', 'pending'),
            $this->state('waiting', 'recorded', 'pending'),
            $this->state('applied', 'applied', 'delivered'),
        ];
        $coordinator = new YSHelcimRefundLocalCoordinator(
            fn (): array => $this->recorded(),
            static function () use (&$workerCalls, &$events): array {
                $events[] = 'worker';
                ++$workerCalls;
                return ['id' => $workerCalls, 'effect_type' => 'effect-' . $workerCalls];
            },
            static function () use (&$finalizerCalls, &$states, &$events): array {
                $events[] = 'finalizer';
                ++$finalizerCalls;
                return array_shift($states);
            },
            static function (string $uuid) use (&$scheduled, &$events): bool {
                $events[] = 'scheduler';
                $scheduled[] = $uuid;
                return true;
            }
        );

        $result = $coordinator->record(self::OPERATION_UUID);

        self::assertIsArray($result);
        self::assertSame('applied', $result['local_status']);
        self::assertSame('delivered', $result['notification_status']);
        self::assertSame(3, $workerCalls);
        self::assertSame(4, $finalizerCalls);
        self::assertSame([self::OPERATION_UUID], $scheduled);
        self::assertTrue($result['recovery_scheduled']);
        self::assertSame('scheduler', $events[0], 'Recovery must be durable before finalizer/worker progress begins.');
    }

    public function testTransientWorkerFailureKeepsRecordedStatusAndSchedulesRecovery(): void
    {
        $scheduled = [];
        $coordinator = new YSHelcimRefundLocalCoordinator(
            fn (): array => $this->recorded(),
            static fn (): \WP_Error => new \WP_Error('ys_helcim_outbox_unavailable', 'db password=secret'),
            fn (): array => $this->state('waiting', 'recorded', 'pending'),
            static function (string $uuid) use (&$scheduled): bool {
                $scheduled[] = $uuid;
                return true;
            }
        );

        $result = $coordinator->record(self::OPERATION_UUID);

        self::assertIsArray($result);
        self::assertSame('recorded', $result['local_status']);
        self::assertSame('attention_required', $result['notification_status']);
        self::assertSame('ys_helcim_outbox_unavailable', $result['effect_error_code']);
        self::assertSame([self::OPERATION_UUID], $scheduled);
        self::assertTrue($result['recovery_scheduled']);
        self::assertStringNotContainsString('password', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testWorkerErrorIsReFinalizedForStockAmbiguityAfterRecoveryWasScheduled(): void
    {
        $scheduled = 0;
        $states = [
            $this->state('waiting', 'recorded', 'pending'),
            $this->state('stock_reconciliation_required', 'recorded', 'attention_required', ['stock_restore']),
        ];
        $coordinator = new YSHelcimRefundLocalCoordinator(
            fn (): array => $this->recorded(),
            static fn (): \WP_Error => new \WP_Error('ys_helcim_effect_payload_invalid', 'invalid'),
            static function () use (&$states): array {
                return array_shift($states);
            },
            static function () use (&$scheduled): bool {
                ++$scheduled;
                return true;
            }
        );

        $result = $coordinator->record(self::OPERATION_UUID);

        self::assertSame('recorded', $result['local_status']);
        self::assertTrue($result['manual_reconciliation_required']);
        self::assertSame('ys_helcim_effect_payload_invalid', $result['effect_error_code']);
        self::assertSame(1, $scheduled);
        self::assertTrue($result['recovery_scheduled']);
    }

    public function testSchedulerFailureIsReportedInsteadOfHidden(): void
    {
        $workerCalls = 0;
        $finalizerCalls = 0;
        $coordinator = new YSHelcimRefundLocalCoordinator(
            fn (): array => $this->recorded(),
            static function () use (&$workerCalls): void { ++$workerCalls; },
            function () use (&$finalizerCalls): array {
                ++$finalizerCalls;
                return $this->state('applied', 'applied', 'delivered');
            },
            static fn (): bool => false,
            1
        );

        $result = $coordinator->record(self::OPERATION_UUID);

        self::assertSame('recorded', $result['local_status']);
        self::assertFalse($result['recovery_scheduled']);
        self::assertSame('attention_required', $result['notification_status']);
        self::assertSame('recovery_not_scheduled', $result['effect_status']);
        self::assertSame(['recovery_scheduling'], $result['warnings']);
        self::assertSame('ys_helcim_recovery_schedule_failed', $result['recovery_error_code']);
        self::assertSame(0, $workerCalls);
        self::assertSame(0, $finalizerCalls);
    }

    public function testSchedulerExceptionStopsBeforeAnyEffectAndReturnsTheSameDurableWarning(): void
    {
        $workerCalls = 0;
        $finalizerCalls = 0;
        $coordinator = new YSHelcimRefundLocalCoordinator(
            fn (): array => $this->recorded(),
            static function () use (&$workerCalls): void { ++$workerCalls; },
            static function () use (&$finalizerCalls): void { ++$finalizerCalls; },
            static function (): never { throw new \RuntimeException('scheduler unavailable'); }
        );

        $result = $coordinator->record(self::OPERATION_UUID);

        self::assertIsArray($result);
        self::assertSame('recorded', $result['local_status']);
        self::assertFalse($result['recovery_scheduled']);
        self::assertSame('attention_required', $result['notification_status']);
        self::assertSame('recovery_not_scheduled', $result['effect_status']);
        self::assertSame(['recovery_scheduling'], $result['warnings']);
        self::assertSame('ys_helcim_recovery_schedule_failed', $result['recovery_error_code']);
        self::assertSame(0, $workerCalls);
        self::assertSame(0, $finalizerCalls);
    }

    public function testStockAmbiguityStopsEffectWorkAfterRecoveryWasAlreadyScheduled(): void
    {
        $scheduled = 0;
        $coordinator = new YSHelcimRefundLocalCoordinator(
            fn (): array => $this->recorded(),
            static fn (): null => null,
            fn (): array => $this->state('stock_reconciliation_required', 'recorded', 'attention_required', ['stock_restore']),
            static function () use (&$scheduled): bool {
                ++$scheduled;
                return true;
            }
        );

        $result = $coordinator->record(self::OPERATION_UUID);

        self::assertSame('recorded', $result['local_status']);
        self::assertTrue($result['manual_reconciliation_required']);
        self::assertSame(['stock_restore'], $result['warnings']);
        self::assertSame(1, $scheduled);
        self::assertTrue($result['recovery_scheduled']);
    }

    public function testReplayedTerminalManualOperationDoesNotScheduleForever(): void
    {
        $scheduled = 0;
        $coordinator = new YSHelcimRefundLocalCoordinator(
            fn (): array => array_merge($this->recorded(), ['replayed' => true]),
            static fn (): null => null,
            fn (): array => $this->state('stock_reconciliation_required', 'recorded', 'attention_required', ['stock_restore']),
            static function () use (&$scheduled): bool {
                ++$scheduled;
                return true;
            }
        );

        $result = $coordinator->record(self::OPERATION_UUID);

        self::assertSame('stock_reconciliation_required', $result['effect_status']);
        self::assertTrue($result['manual_reconciliation_required']);
        self::assertSame(0, $scheduled);
    }

    public function testStillWaitingAfterTheBoundedDrainSchedulesOneRecovery(): void
    {
        $workerCalls = 0;
        $scheduled = 0;
        $coordinator = new YSHelcimRefundLocalCoordinator(
            fn (): array => $this->recorded(),
            static function () use (&$workerCalls): array {
                ++$workerCalls;
                return ['id' => $workerCalls, 'effect_type' => 'customer_recount'];
            },
            fn (): array => $this->state('waiting', 'recorded', 'pending'),
            static function () use (&$scheduled): bool {
                ++$scheduled;
                return true;
            },
            2
        );

        $result = $coordinator->record(self::OPERATION_UUID);

        self::assertSame('recorded', $result['local_status']);
        self::assertSame(2, $workerCalls);
        self::assertSame(1, $scheduled);
    }

    public function testDurableSchedulerCallbackCanReenterAfterAWorkerCrashAndFinish(): void
    {
        $recorderCalls = 0;
        $workerCalls = 0;
        $scheduled = [];
        $recoveryDurable = false;
        $states = [
            $this->state('waiting', 'recorded', 'pending'),
            $this->state('waiting', 'recorded', 'pending'),
            $this->state('waiting', 'recorded', 'pending'),
            $this->state('applied', 'applied', 'delivered'),
        ];
        $coordinator = new YSHelcimRefundLocalCoordinator(
            function () use (&$recorderCalls): array {
                ++$recorderCalls;
                return array_merge($this->recorded(), ['replayed' => $recorderCalls > 1]);
            },
            static function () use (&$workerCalls, &$recoveryDurable): array {
                self::assertTrue($recoveryDurable, 'The recovery event must exist before entering effect code.');
                ++$workerCalls;
                if ($workerCalls === 1) {
                    throw new \RuntimeException('simulated process boundary');
                }
                return ['id' => 31, 'effect_type' => 'refund_hooks'];
            },
            static function () use (&$states): array {
                return array_shift($states);
            },
            static function (string $uuid) use (&$scheduled, &$recoveryDurable): bool {
                $scheduled[] = $uuid;
                $recoveryDurable = true;
                return true;
            },
            1
        );

        $first = $coordinator->record(self::OPERATION_UUID);
        self::assertSame('recorded', $first['local_status']);
        self::assertTrue($first['recovery_scheduled']);
        self::assertSame('ys_helcim_effect_processing_failed', $first['effect_error_code']);

		$second = $coordinator->record($scheduled[0]);

        self::assertSame('applied', $second['local_status']);
        self::assertSame('delivered', $second['notification_status']);
        self::assertSame(2, $recorderCalls);
        self::assertSame(2, $workerCalls);
		self::assertSame([self::OPERATION_UUID], $scheduled, 'A replay relies on the recurring sweep and must not create terminal cron churn.');
    }

    /** @return array<string,mixed> */
    private function recorded(): array
    {
        return [
            'operation_uuid' => self::OPERATION_UUID,
            'local_transaction_id' => 44,
            'local_status' => 'recorded',
            'replayed' => false,
        ];
    }

    /** @param string[] $warnings @return array<string,mixed> */
    private function state(string $status, string $localStatus, string $notificationStatus, array $warnings = []): array
    {
        return [
            'status' => $status,
            'local_status' => $localStatus,
            'notification_status' => $notificationStatus,
            'warnings' => $warnings,
            'effect_statuses' => [],
            'replayed' => false,
        ];
    }
}
