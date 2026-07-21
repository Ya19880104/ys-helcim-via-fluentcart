<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Webhook;

use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimPurchaseOperation;
use YangSheep\Helcim\FluentCart\Webhook\YSHelcimWebhookPurchaseReconciler;

final class WebhookPurchaseReconcilerTest extends TestCase
{
    private const OPERATION_UUID = '00000000-0000-4000-8000-000000000321';

    public function testExactCorrelatedAccountProofReconcilesTheExactLocalTransaction(): void
    {
        $calls = [];
        $transaction = (object) ['id' => 20];
        $runtime = new class($calls) {
            public function __construct(private array &$calls) {}
            public function reconcileProviderProof(object $transaction, string $uuid, array $proof): array
            {
                $this->calls[] = [$transaction, $uuid, $proof];
                return [
                    'status' => 'succeeded',
                    'remote_status' => 'succeeded',
                    'local_status' => 'applied',
                    'error_code' => null,
                ];
            }
        };
        $reconciler = $this->reconciler($runtime, $transaction);

        $result = $reconciler->reconcile(
            $this->proof(),
            '51177123',
            [['gateway' => 'ys_helcim_js', 'mode' => 'test']]
        );

        self::assertSame(['code' => 200, 'message' => 'payment reconciled'], $result);
        self::assertCount(1, $calls);
        self::assertSame($transaction, $calls[0][0]);
        self::assertSame(self::OPERATION_UUID, $calls[0][1]);
        self::assertSame(self::OPERATION_UUID, $calls[0][2]['operation_correlation']);
        self::assertSame('succeeded', $calls[0][2]['outcome']);
        self::assertSame('51177123', $calls[0][2]['transaction']['transactionId']);
    }

    public function testWrongAccountBindingNeverReachesTheRuntime(): void
    {
        $runtimeCalls = 0;
        $runtime = new class($runtimeCalls) {
            public function __construct(private int &$calls) {}
            public function reconcileProviderProof(): array
            {
                ++$this->calls;
                return [];
            }
        };
        $result = $this->reconciler($runtime)->reconcile(
            $this->proof(),
            '51177123',
            [['gateway' => 'ys_helcim_js', 'mode' => 'live']]
        );

        self::assertSame(409, $result['code']);
        self::assertSame(0, $runtimeCalls);
    }

    public function testAcceptsRealWpdbDecimalStrings(): void
    {
        $row = $this->operation();
        $row['order_id'] = '10';
        $row['transaction_id'] = '20';
        $row['amount'] = '2100';
        $calls = 0;
        $runtime = new class($calls) {
            public function __construct(private int &$calls) {}
            public function reconcileProviderProof(): array
            {
                ++$this->calls;
                return [
                    'status' => 'succeeded',
                    'remote_status' => 'succeeded',
                    'local_status' => 'applied',
                    'error_code' => null,
                ];
            }
        };
        $reconciler = new YSHelcimWebhookPurchaseReconciler(
            static fn (): array => $row,
            static fn (int $id): object => (object) ['id' => $id],
            static fn (): object => $runtime
        );

        self::assertSame(
            ['code' => 200, 'message' => 'payment reconciled'],
            $reconciler->reconcile($this->proof(), '51177123', [['gateway' => 'ys_helcim_js', 'mode' => 'test']])
        );
        self::assertSame(1, $calls);
    }

    public function testReplayedDefinitiveDeclineIsAcknowledgedWithoutCreatingANewCharge(): void
    {
        $row = $this->operation();
        $row['remote_status'] = 'declined';
        $proof = $this->proof();
        $proof['status'] = 'DECLINED';
        $proof['approvalCode'] = '';
        $calls = 0;
        $runtime = new class($calls) {
            public function __construct(private int &$calls) {}
            public function reconcileProviderProof(): array
            {
                ++$this->calls;
                return [
                    'status' => 'declined',
                    'remote_status' => 'declined',
                    'local_status' => 'pending',
                    'error_code' => 'card_declined',
                ];
            }
        };
        $reconciler = new YSHelcimWebhookPurchaseReconciler(
            static fn (): array => $row,
            static fn (): object => (object) ['id' => 20],
            static fn (): object => $runtime
        );

        self::assertSame(
            ['code' => 200, 'message' => 'payment reconciled'],
            $reconciler->reconcile($proof, '51177123', [['gateway' => 'ys_helcim_js', 'mode' => 'test']])
        );
        self::assertSame(1, $calls);
    }

    public function testUnknownInvoiceCorrelationNeverSelectsByAmountOrRecency(): void
    {
        $operationReads = 0;
        $reconciler = new YSHelcimWebhookPurchaseReconciler(
            static function () use (&$operationReads): null {
                ++$operationReads;
                return null;
            },
            static fn (): object => (object) ['id' => 20],
            static fn (): object => new \stdClass()
        );
        $proof = $this->proof();
        $proof['invoiceNumber'] = 'not-an-operation-uuid';

        $result = $reconciler->reconcile(
            $proof,
            '51177123',
            [['gateway' => 'ys_helcim_js', 'mode' => 'test']]
        );

        self::assertSame(409, $result['code']);
        self::assertSame(0, $operationReads);
    }

    public function testProviderIdMismatchAndRefundTypeFailBeforeLocalMutation(): void
    {
        $runtimeCalls = 0;
        $runtime = new class($runtimeCalls) {
            public function __construct(private int &$calls) {}
            public function reconcileProviderProof(): array
            {
                ++$this->calls;
                return [];
            }
        };
        $reconciler = $this->reconciler($runtime);

        $idMismatch = $reconciler->reconcile(
            $this->proof(),
            '51177999',
            [['gateway' => 'ys_helcim_js', 'mode' => 'test']]
        );
        $refund = $this->proof();
        $refund['type'] = 'refund';
        $wrongType = $reconciler->reconcile(
            $refund,
            '51177123',
            [['gateway' => 'ys_helcim_js', 'mode' => 'test']]
        );

        self::assertSame(400, $idMismatch['code']);
        self::assertSame(422, $wrongType['code']);
        self::assertSame(0, $runtimeCalls);
    }

    public function testProvenRemoteSuccessWithIncompleteLocalApplyRequestsProviderRetry(): void
    {
        $runtime = new class {
            public function reconcileProviderProof(): array
            {
                return [
                    'status' => 'attention_required',
                    'remote_status' => 'succeeded',
                    'local_status' => 'failed',
                    'error_code' => 'local_bind_failed',
                ];
            }
        };

        $result = $this->reconciler($runtime)->reconcile(
            $this->proof(),
            '51177123',
            [['gateway' => 'ys_helcim_js', 'mode' => 'test']]
        );

        self::assertSame(['code' => 503, 'message' => 'local payment reconciliation incomplete'], $result);
    }

    public function testSecondJournalReadFailureAndUnpersistedOutcomeAreRetryable(): void
    {
        $readFailure = new YSHelcimWebhookPurchaseReconciler(
            static fn (): \WP_Error => new \WP_Error('ys_helcim_journal_unavailable', 'secret database detail'),
            static fn (): object => (object) ['id' => 20],
            static fn (): object => new \stdClass()
        );
        self::assertSame(
            ['code' => 503, 'message' => 'operation journal unavailable'],
            $readFailure->reconcile(
                $this->proof(),
                '51177123',
                [['gateway' => 'ys_helcim_js', 'mode' => 'test']]
            )
        );

        $runtime = new class {
            public function reconcileProviderProof(): array
            {
                return [
                    'status' => 'indeterminate',
                    'remote_status' => 'indeterminate',
                    'local_status' => 'pending',
                    'error_code' => 'journal_outcome_unpersisted',
                ];
            }
        };
        self::assertSame(
            ['code' => 503, 'message' => 'payment reconciliation incomplete'],
            $this->reconciler($runtime)->reconcile(
                $this->proof(),
                '51177123',
                [['gateway' => 'ys_helcim_js', 'mode' => 'test']]
            )
        );
    }

    private function reconciler(object $runtime, ?object $transaction = null): YSHelcimWebhookPurchaseReconciler
    {
        $operation = $this->operation();
        return new YSHelcimWebhookPurchaseReconciler(
            static fn (string $uuid): ?array => $uuid === self::OPERATION_UUID ? $operation : null,
            static fn (int $id): ?object => $id === 20 ? ($transaction ?? (object) ['id' => 20]) : null,
            static fn (string $gateway, string $mode): object => $runtime
        );
    }

    /** @return array<string,mixed> */
    private function operation(): array
    {
        $purchase = YSHelcimPurchaseOperation::fromTransaction([
            'gateway' => 'ys_helcim_js',
            'order_id' => 10,
            'transaction_id' => 20,
            'transaction_uuid' => 'fc-transaction-123',
            'amount' => 2100,
            'currency' => 'USD',
            'payment_mode' => 'test',
        ]);
        self::assertInstanceOf(YSHelcimPurchaseOperation::class, $purchase);
        $row = $purchase->repositoryRecord(self::OPERATION_UUID, hash_hmac('sha256', 'card-token', wp_salt('auth')));
        self::assertIsArray($row);
        return $row + [
            'remote_status' => 'indeterminate',
            'local_status' => 'pending',
        ];
    }

    /** @return array<string,mixed> */
    private function proof(): array
    {
        return [
            'transactionId' => 51177123,
            'status' => 'APPROVED',
            'type' => 'purchase',
            'amount' => '21.00',
            'currency' => 'USD',
            'invoiceNumber' => self::OPERATION_UUID,
        ];
    }
}
