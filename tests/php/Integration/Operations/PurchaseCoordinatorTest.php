<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Integration\Operations;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimIdempotency;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationRepository;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimPurchaseCoordinator;
use YangSheep\Helcim\FluentCart\Tests\Doubles\FakeWpdb;

final class PurchaseCoordinatorTest extends TestCase
{
    private const OPERATION_UUID = '00000000-0000-4000-8000-000000000321';

    private FakeWpdb $database;

    private YSHelcimOperationRepository $repository;

    protected function setUp(): void
    {
        $this->database = new FakeWpdb();
        $this->repository = new YSHelcimOperationRepository(
            $this->database,
            static fn (): string => '2026-07-21 00:00:00'
        );
    }

    public function testFirstPurchasePersistsStableOperationBeforeCallingProviderAndBindsExactId(): void
    {
        $providerCalls = [];
        $binderCalls = [];
        $coordinator = $this->coordinator(
            function (array $identity, string $cardToken, string $idempotencyKey, string $operationUuid) use (&$providerCalls): array {
                $providerCalls[] = [$identity, $cardToken, $idempotencyKey, $operationUuid, $this->database->allRows()];
                return self::approvedResponse();
            },
            static function (array $identity, string $providerId, string $operationUuid) use (&$binderCalls): array {
                $binderCalls[] = [$identity, $providerId, $operationUuid];
                return ['bound' => true, 'provider_transaction_id' => $providerId];
            }
        );

        $result = $coordinator->execute($this->transaction(), 'card-token-secret');

        self::assertSame('succeeded', $result['status']);
        self::assertSame(self::OPERATION_UUID, $result['operation_uuid']);
        self::assertSame('51177123', $result['provider_transaction_id']);
        self::assertSame('succeeded', $result['remote_status']);
        self::assertSame('applied', $result['local_status']);
        self::assertFalse($result['replayed']);
        self::assertCount(1, $providerCalls);
        self::assertSame('card-token-secret', $providerCalls[0][1]);
        self::assertSame(
            YSHelcimIdempotency::generate('purchase', 'fc-transaction-123', 2100, 'test', self::OPERATION_UUID),
            $providerCalls[0][2]
        );
        self::assertSame(self::OPERATION_UUID, $providerCalls[0][3]);
        self::assertCount(1, $providerCalls[0][4], 'The operation must be durable before the provider call.');
        self::assertSame('processing', $providerCalls[0][4][0]['remote_status']);
        self::assertCount(1, $binderCalls);
        self::assertSame('51177123', $binderCalls[0][1]);
        self::assertSame(self::OPERATION_UUID, $binderCalls[0][2]);

        $stored = $this->repository->findByUuid(self::OPERATION_UUID);
        self::assertSame('51177123', $stored['vendor_transaction_id']);
        self::assertSame('succeeded', $stored['remote_status']);
        self::assertSame('applied', $stored['local_status']);
        self::assertNull($stored['encrypted_material']);
        self::assertStringNotContainsString('card-token-secret', json_encode($this->database->allRows(), JSON_THROW_ON_ERROR));
    }

    public function testConcurrentSecondRequestCannotCallProviderAgain(): void
    {
        $providerCalls = 0;
        $binderCalls = 0;
        $nestedResult = null;
        $coordinator = null;
        $provider = function () use (&$providerCalls, &$nestedResult, &$coordinator): array {
            $providerCalls++;
            $nestedResult = $coordinator->execute($this->transaction(), 'first-card-token');
            return self::approvedResponse();
        };
        $binder = static function (array $identity, string $providerId) use (&$binderCalls): array {
            unset($identity);
            $binderCalls++;
            return ['bound' => true, 'provider_transaction_id' => $providerId];
        };
        $coordinator = $this->coordinator($provider, $binder);

        $firstResult = $coordinator->execute($this->transaction(), 'first-card-token');

        self::assertSame('succeeded', $firstResult['status']);
        self::assertSame('indeterminate', $nestedResult['status']);
        self::assertSame('processing', $nestedResult['remote_status']);
        self::assertTrue($nestedResult['replayed']);
        self::assertSame(1, $providerCalls);
        self::assertSame(1, $binderCalls);
    }

    public function testFreshTokenRecoversAnAbandonedCreatedAttemptAfterTheClaimLease(): void
    {
        $now = '2026-07-21 00:00:00';
        $this->repository = new YSHelcimOperationRepository(
            $this->database,
            static function () use (&$now): string {
                return $now;
            }
        );
        $providerCalls = 0;
        $operationUuids = [
            self::OPERATION_UUID,
            '00000000-0000-4000-8000-000000000322',
        ];
        $coordinator = $this->coordinator(
            static function () use (&$providerCalls): array {
                ++$providerCalls;
                return self::approvedResponse();
            },
            static fn (array $identity, string $providerId): array => [
                'bound' => true,
                'provider_transaction_id' => $providerId,
            ],
            static function () use (&$operationUuids): string {
                return (string) array_shift($operationUuids);
            },
            null,
            static function () use (&$now): string {
                return $now;
            }
        );
        $this->database->failNextUpdate = true;

        $abandoned = $coordinator->execute($this->transaction(), 'first-card-token');
        self::assertInstanceOf(\WP_Error::class, $abandoned);
        self::assertSame('created', $this->repository->findByUuid(self::OPERATION_UUID)['remote_status']);
        self::assertSame(0, $providerCalls);

        $now = '2026-07-21 00:06:00';
        $recovered = $coordinator->execute($this->transaction(), 'fresh-card-token');

        self::assertSame('succeeded', $recovered['status']);
        self::assertSame('00000000-0000-4000-8000-000000000322', $recovered['operation_uuid']);
        self::assertSame(1, $providerCalls);
        $old = $this->repository->findByUuid(self::OPERATION_UUID);
        self::assertSame('expired', $old['remote_status']);
        self::assertSame('failed', $old['local_status']);
        self::assertNull($old['active_scope_key']);
        self::assertStringNotContainsString('first-card-token', json_encode($this->database->allRows(), JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('fresh-card-token', json_encode($this->database->allRows(), JSON_THROW_ON_ERROR));
    }

    public function testTimeoutBecomesDurableIndeterminateAndReplayNeverRecharges(): void
    {
        $providerCalls = 0;
        $binderCalls = 0;
        $coordinator = $this->coordinator(
            static function () use (&$providerCalls): never {
                $providerCalls++;
                throw new \RuntimeException('timeout card-token-secret');
            },
            static function () use (&$binderCalls): array {
                $binderCalls++;
                return ['bound' => true, 'provider_transaction_id' => '51177123'];
            }
        );

        $first = $coordinator->execute($this->transaction(), 'card-token-secret');
        $second = $coordinator->execute($this->transaction(), 'card-token-secret');
        $differentAttempt = $coordinator->execute($this->transaction(), 'different-card-token');

        self::assertSame('indeterminate', $first['status']);
        self::assertSame('indeterminate', $second['status']);
        self::assertSame('indeterminate', $second['remote_status']);
        self::assertTrue($second['replayed']);
        self::assertSame('attention_required', $differentAttempt['status']);
        self::assertSame('purchase_attempt_in_progress', $differentAttempt['error_code']);
        self::assertSame(1, $providerCalls);
        self::assertSame(0, $binderCalls);
        self::assertStringNotContainsString('card-token-secret', json_encode($this->database->allRows(), JSON_THROW_ON_ERROR));
    }

    #[DataProvider('unprovenProviderOutcomes')]
    public function testAnythingOtherThanStrictSuccessOrStrictDeclineIsIndeterminate(mixed $providerOutcome): void
    {
        $binderCalls = 0;
        $coordinator = $this->coordinator(
            static fn (): mixed => $providerOutcome,
            static function () use (&$binderCalls): array {
                $binderCalls++;
                return ['bound' => true, 'provider_transaction_id' => '51177123'];
            }
        );

        $result = $coordinator->execute($this->transaction(), 'card-token-secret');

        self::assertSame('indeterminate', $result['status']);
        self::assertSame('indeterminate', $result['remote_status']);
        self::assertSame(0, $binderCalls);
    }

    public static function unprovenProviderOutcomes(): iterable
    {
        yield 'WP Error that claims decline' => [new \WP_Error('declined', 'Declined', ['definitive' => true])];
        yield 'raw Helcim decline without strict callback envelope' => [[
            'status' => 'DECLINED',
            'type' => 'purchase',
            'amount' => '21.00',
            'currency' => 'USD',
        ]];
        yield 'decline without definitive proof bit' => [[
            'outcome' => 'declined',
            'transaction' => [
                'status' => 'DECLINED',
                'type' => 'purchase',
                'amount' => '21.00',
                'currency' => 'USD',
            ],
        ]];
        yield 'decline envelope with wrong status' => [[
            'outcome' => 'declined',
            'definitive' => true,
            'transaction' => [
                'status' => 'ERROR',
                'type' => 'purchase',
                'amount' => '21.00',
                'currency' => 'USD',
            ],
        ]];
        yield 'approved response with wrong amount' => [[
            'outcome' => 'succeeded',
            'transaction' => [
                'status' => 'APPROVED',
                'type' => 'purchase',
                'transactionId' => '51177123',
                'amount' => '21.01',
                'currency' => 'USD',
            ],
        ]];
    }

    public function testStrictDeclineIsTerminalAndNeverBindsOrRecharges(): void
    {
        $providerCalls = 0;
        $binderCalls = 0;
        $coordinator = $this->coordinator(
            static function () use (&$providerCalls): array {
                $providerCalls++;
                return self::declinedResponse();
            },
            static function () use (&$binderCalls): array {
                $binderCalls++;
                return ['bound' => true, 'provider_transaction_id' => '51177123'];
            }
        );

        $first = $coordinator->execute($this->transaction(), 'card-token-secret');
        $second = $coordinator->execute($this->transaction(), 'card-token-secret');

        self::assertSame('declined', $first['status']);
        self::assertSame('declined', $second['status']);
        self::assertSame('declined', $second['remote_status']);
        self::assertTrue($second['replayed']);
        self::assertSame(1, $providerCalls);
        self::assertSame(0, $binderCalls, 'A decline must never mark the FluentCart payment paid.');
        self::assertCount(1, $this->database->allRows(), 'A released active scope must still replay the terminal purchase row.');
    }

    public function testDefinitiveDeclineAllowsDifferentCardSuccessorWithNewUuidAndKey(): void
    {
        $providerCalls = 0;
        $binderCalls = 0;
        $uuids = [
            '00000000-0000-4000-8000-000000000321',
            '00000000-0000-4000-8000-000000000322',
        ];
        $coordinator = $this->coordinator(
            static function () use (&$providerCalls): array {
                $providerCalls++;
                return 1 === $providerCalls ? self::declinedResponse() : self::approvedResponse('51177124');
            },
            static function (array $identity, string $providerId) use (&$binderCalls): array {
                unset($identity);
                $binderCalls++;
                return ['bound' => true, 'provider_transaction_id' => $providerId];
            },
            static function () use (&$uuids): string {
                return array_shift($uuids);
            }
        );

        $declined = $coordinator->execute($this->transaction(), 'first-card-token');
        $succeeded = $coordinator->execute($this->transaction(), 'different-card-token');
        $oldReplay = $coordinator->execute($this->transaction(), 'first-card-token');

        self::assertSame('declined', $declined['status']);
        self::assertSame('succeeded', $succeeded['status']);
        self::assertSame('51177124', $succeeded['provider_transaction_id']);
        self::assertSame('declined', $oldReplay['status']);
        self::assertSame($declined['operation_uuid'], $oldReplay['operation_uuid']);
        self::assertSame(2, $providerCalls);
        self::assertSame(1, $binderCalls);

        $rows = $this->database->allRows();
        self::assertCount(2, $rows);
        self::assertNotSame($rows[0]['operation_uuid'], $rows[1]['operation_uuid']);
        self::assertNotSame($rows[0]['idempotency_key'], $rows[1]['idempotency_key']);
        self::assertSame('declined', $rows[0]['remote_status']);
        self::assertSame('succeeded', $rows[1]['remote_status']);
        self::assertStringNotContainsString('first-card-token', json_encode($rows, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('different-card-token', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    public function testDefinitiveNoChargeFailureReleasesScopeAndAllowsFreshAttemptWithoutBinding(): void
    {
        $providerCalls = 0;
        $binderCalls = 0;
        $uuids = [
            '00000000-0000-4000-8000-000000000321',
            '00000000-0000-4000-8000-000000000322',
        ];
        $coordinator = $this->coordinator(
            static function () use (&$providerCalls): array {
                ++$providerCalls;
                return 1 === $providerCalls
                    ? [
                        'outcome' => 'failed',
                        'definitive' => true,
                        'mutation_disposition' => 'authentication_rejected',
                    ]
                    : self::approvedResponse('51177124');
            },
            static function (array $identity, string $providerId) use (&$binderCalls): array {
                unset($identity);
                ++$binderCalls;
                return ['bound' => true, 'provider_transaction_id' => $providerId];
            },
            static function () use (&$uuids): string {
                return (string) array_shift($uuids);
            }
        );

        $failed = $coordinator->execute($this->transaction(), 'first-card-token');
        $replayedFailure = $coordinator->execute($this->transaction(), 'first-card-token');
        $succeeded = $coordinator->execute($this->transaction(), 'fresh-card-token');

        self::assertSame('failed', $failed['status']);
        self::assertSame('failed', $failed['remote_status']);
        self::assertSame('failed', $replayedFailure['status']);
        self::assertTrue($replayedFailure['replayed']);
        self::assertSame('succeeded', $succeeded['status']);
        self::assertSame(2, $providerCalls);
        self::assertSame(1, $binderCalls, 'A no-charge failure must never bind the FluentCart transaction paid.');

        $rows = $this->database->allRows();
        self::assertCount(2, $rows);
        self::assertSame('failed', $rows[0]['remote_status']);
        self::assertNull($rows[0]['active_scope_key']);
        self::assertNull($rows[0]['vendor_transaction_id']);
        self::assertSame('succeeded', $rows[1]['remote_status']);
    }

    public function testValidationRejectedFailureIsTerminalReplaySafeAndReleasesScopeForFreshToken(): void
    {
        $providerCalls = 0;
        $binderCalls = 0;
        $uuids = [
            '00000000-0000-4000-8000-000000000321',
            '00000000-0000-4000-8000-000000000322',
        ];
        $coordinator = $this->coordinator(
            static function () use (&$providerCalls): array {
                ++$providerCalls;
                return 1 === $providerCalls
                    ? [
                        'outcome' => 'failed',
                        'definitive' => true,
                        'mutation_disposition' => 'validation_rejected',
                    ]
                    : self::approvedResponse('51177124');
            },
            static function (array $identity, string $providerId) use (&$binderCalls): array {
                unset($identity);
                ++$binderCalls;
                return ['bound' => true, 'provider_transaction_id' => $providerId];
            },
            static function () use (&$uuids): string {
                return (string) array_shift($uuids);
            }
        );

        $failed = $coordinator->execute($this->transaction(), 'unverified-card-token');
        $replayedFailure = $coordinator->execute($this->transaction(), 'unverified-card-token');
        $succeeded = $coordinator->execute($this->transaction(), 'fresh-card-token');

        self::assertSame('failed', $failed['status']);
        self::assertSame('provider_validation_rejected', $failed['error_code']);
        self::assertSame('failed', $replayedFailure['status']);
        self::assertSame($failed['operation_uuid'], $replayedFailure['operation_uuid']);
        self::assertTrue($replayedFailure['replayed']);
        self::assertSame('succeeded', $succeeded['status']);
        self::assertSame(2, $providerCalls, 'The failed token replay must not call the provider again.');
        self::assertSame(1, $binderCalls, 'A validation rejection must never bind the FluentCart transaction paid.');

        $rows = $this->database->allRows();
        self::assertCount(2, $rows);
        self::assertSame('failed', $rows[0]['remote_status']);
        self::assertSame('provider_validation_rejected', $rows[0]['remote_error_code']);
        self::assertNull($rows[0]['active_scope_key']);
        self::assertNull($rows[0]['vendor_transaction_id']);
        self::assertSame('succeeded', $rows[1]['remote_status']);
    }

    public function testDelayedOldReplayCannotEnterConcurrentSuccessorOrCreateThirdCharge(): void
    {
        $providerCalls = 0;
        $oldReplay = null;
        $thirdAttempt = null;
        $uuids = [
            '00000000-0000-4000-8000-000000000321',
            '00000000-0000-4000-8000-000000000322',
            '00000000-0000-4000-8000-000000000323',
        ];
        $coordinator = null;
        $provider = function () use (&$providerCalls, &$oldReplay, &$thirdAttempt, &$coordinator): array {
            $providerCalls++;
            if (2 === $providerCalls) {
                $oldReplay = $coordinator->execute($this->transaction(), 'first-card-token');
                $thirdAttempt = $coordinator->execute($this->transaction(), 'third-card-token');
                return self::approvedResponse('51177124');
            }
            return self::declinedResponse();
        };
        $coordinator = $this->coordinator(
            $provider,
            static fn (array $identity, string $providerId): array => [
                'bound' => true,
                'provider_transaction_id' => $providerId,
            ],
            static function () use (&$uuids): string {
                return array_shift($uuids);
            }
        );

        $coordinator->execute($this->transaction(), 'first-card-token');
        $successor = $coordinator->execute($this->transaction(), 'second-card-token');

        self::assertSame('succeeded', $successor['status']);
        self::assertSame('declined', $oldReplay['status']);
        self::assertSame('attention_required', $thirdAttempt['status']);
        self::assertSame('purchase_attempt_in_progress', $thirdAttempt['error_code']);
        self::assertSame(2, $providerCalls, 'Only the declined attempt and its one successor may reach Helcim.');
        self::assertCount(2, $this->database->allRows());
    }

    public function testLocalSaveFailureDoesNotReturnSuccessAndCanRetryBindingWithoutProviderCall(): void
    {
        $providerCalls = 0;
        $binderCalls = 0;
        $coordinator = $this->coordinator(
            static function () use (&$providerCalls): array {
                $providerCalls++;
                return self::approvedResponse();
            },
            static function (array $identity, string $providerId) use (&$binderCalls): array|\WP_Error {
                unset($identity);
                $binderCalls++;
                if (1 === $binderCalls) {
                    return new \WP_Error('database_save_failed', 'Could not save card-token-secret');
                }
                return ['bound' => true, 'provider_transaction_id' => $providerId];
            }
        );

        $first = $coordinator->execute($this->transaction(), 'card-token-secret');
        $afterFailure = $this->repository->findByUuid(self::OPERATION_UUID);
        $second = $coordinator->execute($this->transaction(), '', '51177123');

        self::assertSame('attention_required', $first['status']);
        self::assertSame('succeeded', $afterFailure['remote_status']);
        self::assertSame('failed', $afterFailure['local_status']);
        self::assertSame('succeeded', $second['status']);
        self::assertTrue($second['replayed']);
        self::assertSame(1, $providerCalls);
        self::assertSame(2, $binderCalls);
        self::assertStringNotContainsString('card-token-secret', json_encode($this->database->allRows(), JSON_THROW_ON_ERROR));
    }

    public function testExactTransactionWithIncompleteOrderStateRetriesBinderBeforeSuccess(): void
    {
        $providerCalls = 0;
        $binderCalls = 0;
        $localState = 'unbound';
        $coordinator = $this->coordinator(
            static function () use (&$providerCalls): array {
                $providerCalls++;
                return self::approvedResponse();
            },
            static function (array $identity, string $providerId) use (&$binderCalls, &$localState): array|\WP_Error {
                unset($identity);
                $binderCalls++;
                if (1 === $binderCalls) {
                    $localState = 'partial';
                    return new \WP_Error('order_sync_failed', 'The exact transaction was saved but order-paid proof is absent.');
                }
                $localState = 'bound';
                return ['bound' => true, 'provider_transaction_id' => $providerId];
            },
            null,
            static function (array $identity, string $providerId) use (&$localState): array {
                unset($identity);
                return 'unbound' === $localState
                    ? ['status' => 'unbound', 'provider_transaction_id' => null]
                    : ['status' => $localState, 'provider_transaction_id' => $providerId];
            }
        );

        $first = $coordinator->execute($this->transaction(), 'card-token-secret');
        $second = $coordinator->execute($this->transaction(), 'card-token-secret');

        self::assertSame('attention_required', $first['status']);
        self::assertSame('failed', $first['local_status']);
        self::assertSame('succeeded', $second['status']);
        self::assertSame(1, $providerCalls);
        self::assertSame(2, $binderCalls, 'A partial local aggregate must retry the idempotent binder/order sync.');
        self::assertSame('applied', $this->repository->findByUuid(self::OPERATION_UUID)['local_status']);
    }

    public function testBinderReceiptMustProveTheExactProviderTransactionId(): void
    {
        $coordinator = $this->coordinator(
            static fn (): array => self::approvedResponse(),
            static fn (): array => ['bound' => true, 'provider_transaction_id' => '51177999']
        );

        $result = $coordinator->execute($this->transaction(), 'card-token-secret');

        self::assertSame('attention_required', $result['status']);
        self::assertSame('provider_id_mismatch', $result['error_code']);
        self::assertSame('succeeded', $result['remote_status']);
        self::assertSame('failed', $result['local_status']);
        self::assertSame('51177123', $this->repository->findByUuid(self::OPERATION_UUID)['vendor_transaction_id']);
    }

    public function testUnknownInspectorResultNeverMeansUnbound(): void
    {
        $binderCalls = 0;
        $coordinator = $this->coordinator(
            static fn (): array => self::approvedResponse(),
            static function () use (&$binderCalls): array {
                $binderCalls++;
                return ['bound' => true, 'provider_transaction_id' => '51177123'];
            },
            null,
            static fn (): array => ['status' => 'unbound']
        );

        $result = $coordinator->execute($this->transaction(), 'card-token-secret');

        self::assertSame('attention_required', $result['status']);
        self::assertSame('local_inspection_unknown', $result['error_code']);
        self::assertSame(0, $binderCalls);
        self::assertSame('pending', $this->repository->findByUuid(self::OPERATION_UUID)['local_status']);
    }

    public function testInspectorDifferentProviderIdIsDuplicateAttention(): void
    {
        $binderCalls = 0;
        $coordinator = $this->coordinator(
            static fn (): array => self::approvedResponse(),
            static function () use (&$binderCalls): array {
                $binderCalls++;
                return ['bound' => true, 'provider_transaction_id' => '51177123'];
            },
            null,
            static fn (): array => ['status' => 'mismatch', 'provider_transaction_id' => '51177999']
        );

        $result = $coordinator->execute($this->transaction(), 'card-token-secret');

        self::assertSame('attention_required', $result['status']);
        self::assertSame('provider_id_mismatch', $result['error_code']);
        self::assertSame(0, $binderCalls);
        self::assertSame('pending', $this->repository->findByUuid(self::OPERATION_UUID)['local_status']);
    }

    public function testCrashAfterExactBindBeforeJournalApplyRecoversOnNextCallback(): void
    {
        $providerCalls = 0;
        $binderCalls = 0;
        $externalProviderId = null;
        $inspector = static function () use (&$externalProviderId): array {
            return null === $externalProviderId
                ? ['status' => 'unbound', 'provider_transaction_id' => null]
                : ['status' => 'bound', 'provider_transaction_id' => $externalProviderId];
        };
        $coordinator = $this->coordinator(
            static function () use (&$providerCalls): array {
                $providerCalls++;
                return self::approvedResponse();
            },
            function (array $identity, string $providerId) use (&$binderCalls, &$externalProviderId): array {
                unset($identity);
                $binderCalls++;
                $externalProviderId = $providerId;
                $this->database->failNextUpdate = true;
                return ['bound' => true, 'provider_transaction_id' => $providerId];
            },
            null,
            $inspector
        );

        $first = $coordinator->execute($this->transaction(), 'card-token-secret');
        $crashWindow = $this->repository->findByUuid(self::OPERATION_UUID);
        $second = $coordinator->execute($this->transaction(), 'card-token-secret');

        self::assertSame('attention_required', $first['status']);
        self::assertSame('applying', $crashWindow['local_status']);
        self::assertNotNull($crashWindow['local_claimed_at']);
        self::assertSame('succeeded', $second['status']);
        self::assertSame(1, $providerCalls);
        self::assertSame(1, $binderCalls, 'Exact inspector proof must repair the journal without rebinding.');
        self::assertSame('applied', $this->repository->findByUuid(self::OPERATION_UUID)['local_status']);
        self::assertNull($this->repository->findByUuid(self::OPERATION_UUID)['local_claimed_at']);
    }

    public function testUnboundApplyingAttemptCanOnlyRebindAfterDurableLeaseIsStale(): void
    {
        $now = '2026-07-21 00:00:00';
        $this->repository = new YSHelcimOperationRepository(
            $this->database,
            static function () use (&$now): string {
                return $now;
            }
        );
        $binderCalls = 0;
        $coordinator = $this->coordinator(
            static fn (): array => self::approvedResponse(),
            function (array $identity, string $providerId) use (&$binderCalls): array {
                unset($identity);
                $binderCalls++;
                if (1 === $binderCalls) {
                    $this->database->failNextUpdate = true;
                    throw new \RuntimeException('simulated process death before bind');
                }
                return ['bound' => true, 'provider_transaction_id' => $providerId];
            },
            null,
            static fn (): array => ['status' => 'unbound', 'provider_transaction_id' => null],
            static function () use (&$now): string {
                return $now;
            }
        );

        $first = $coordinator->execute($this->transaction(), 'card-token-secret');
        $now = '2026-07-21 00:04:00';
        $tooEarly = $coordinator->execute($this->transaction(), 'card-token-secret');
        $binderCallsBeforeStale = $binderCalls;
        $now = '2026-07-21 00:06:00';
        $recovered = $coordinator->execute($this->transaction(), 'card-token-secret');

        self::assertSame('attention_required', $first['status']);
        self::assertSame('attention_required', $tooEarly['status']);
        self::assertSame('local_binding_in_progress', $tooEarly['error_code']);
        self::assertSame(1, $binderCallsBeforeStale);
        self::assertSame('succeeded', $recovered['status']);
        self::assertSame(2, $binderCalls);
    }

    public function testRemoteSuccessMustBeDurableBeforeBinderAndJournalFailureIsNotSuccess(): void
    {
        $binderCalls = 0;
        $coordinator = $this->coordinator(
            function (): array {
                $this->database->failNextUpdate = true;
                return self::approvedResponse();
            },
            static function () use (&$binderCalls): array {
                $binderCalls++;
                return ['bound' => true, 'provider_transaction_id' => '51177123'];
            }
        );

        $result = $coordinator->execute($this->transaction(), 'card-token-secret');

        self::assertSame('indeterminate', $result['status']);
        self::assertSame('journal_outcome_unpersisted', $result['error_code']);
        self::assertSame(0, $binderCalls);
        self::assertSame('processing', $this->repository->findByUuid(self::OPERATION_UUID)['remote_status']);
    }

    public function testWebhookApprovedProofRecoversTimedOutAttemptWithoutProviderRecall(): void
    {
        $providerCalls = 0;
        $binderCalls = 0;
        $coordinator = $this->coordinator(
            static function () use (&$providerCalls): never {
                $providerCalls++;
                throw new \RuntimeException('timeout');
            },
            static function (array $identity, string $providerId) use (&$binderCalls): array {
                unset($identity);
                $binderCalls++;
                return ['bound' => true, 'provider_transaction_id' => $providerId];
            }
        );
        $timedOut = $coordinator->execute($this->transaction(), 'card-token-secret');

        $reconciled = $coordinator->reconcileProviderProof(
            $this->transaction(),
            self::OPERATION_UUID,
            self::correlatedProof(self::approvedResponse(), self::OPERATION_UUID)
        );

        self::assertSame('indeterminate', $timedOut['status']);
        self::assertSame('succeeded', $reconciled['status']);
        self::assertSame('51177123', $reconciled['provider_transaction_id']);
        self::assertSame(1, $providerCalls);
        self::assertSame(1, $binderCalls);
    }

    public function testWebhookStrictDeclineResolvesIndeterminateAttemptWithoutBinding(): void
    {
        $providerCalls = 0;
        $binderCalls = 0;
        $coordinator = $this->coordinator(
            static function () use (&$providerCalls): \WP_Error {
                $providerCalls++;
                return new \WP_Error('http_500', 'Unknown provider response');
            },
            static function () use (&$binderCalls): array {
                $binderCalls++;
                return ['bound' => true, 'provider_transaction_id' => '51177123'];
            }
        );
        $coordinator->execute($this->transaction(), 'card-token-secret');

        $reconciled = $coordinator->reconcileProviderProof(
            $this->transaction(),
            self::OPERATION_UUID,
            self::correlatedProof(self::declinedResponse(), self::OPERATION_UUID)
        );

        self::assertSame('declined', $reconciled['status']);
        self::assertSame('declined', $reconciled['remote_status']);
        self::assertSame(1, $providerCalls);
        self::assertSame(0, $binderCalls);
    }

    public function testWebhookProofCannotBeAppliedToWrongAttemptOrDriftedIdentity(): void
    {
        $providerCalls = 0;
        $binderCalls = 0;
        $uuids = [
            '00000000-0000-4000-8000-000000000321',
            '00000000-0000-4000-8000-000000000322',
        ];
        $coordinator = $this->coordinator(
            static function () use (&$providerCalls): array|\WP_Error {
                $providerCalls++;
                return 1 === $providerCalls
                    ? self::declinedResponse()
                    : new \WP_Error('timeout', 'Unknown');
            },
            static function () use (&$binderCalls): array {
                $binderCalls++;
                return ['bound' => true, 'provider_transaction_id' => '51177124'];
            },
            static function () use (&$uuids): string {
                return array_shift($uuids);
            }
        );
        $first = $coordinator->execute($this->transaction(), 'first-card-token');
        $second = $coordinator->execute($this->transaction(), 'second-card-token');

        $wrongAttempt = $coordinator->reconcileProviderProof(
            $this->transaction(),
            $first['operation_uuid'],
            self::correlatedProof(self::approvedResponse('51177124'), $first['operation_uuid'])
        );
        $driftedIdentity = $coordinator->reconcileProviderProof(
            $this->transaction(['amount' => 2200]),
            $second['operation_uuid'],
            self::correlatedProof(self::approvedResponse('51177124'), $second['operation_uuid'])
        );

        self::assertSame('attention_required', $wrongAttempt['status']);
        self::assertSame('attempt_status_conflict', $wrongAttempt['error_code']);
        self::assertSame('attention_required', $driftedIdentity['status']);
        self::assertSame('operation_identity_mismatch', $driftedIdentity['error_code']);
        self::assertSame('indeterminate', $this->repository->findByUuid($second['operation_uuid'])['remote_status']);
        self::assertSame(2, $providerCalls);
        self::assertSame(0, $binderCalls);
    }

    public function testWebhookSameAmountProofWithDifferentCorrelationCannotBindTimedOutAttempt(): void
    {
        $providerCalls = 0;
        $binderCalls = 0;
        $otherOperationUuid = '00000000-0000-4000-8000-000000000999';
        $coordinator = $this->coordinator(
            static function () use (&$providerCalls): never {
                $providerCalls++;
                throw new \RuntimeException('timeout');
            },
            static function () use (&$binderCalls): array {
                $binderCalls++;
                return ['bound' => true, 'provider_transaction_id' => '51177999'];
            }
        );
        $coordinator->execute($this->transaction(), 'card-token-secret');

        $result = $coordinator->reconcileProviderProof(
            $this->transaction(),
            self::OPERATION_UUID,
            self::correlatedProof(self::approvedResponse('51177999'), $otherOperationUuid)
        );

        self::assertSame('attention_required', $result['status']);
        self::assertSame('provider_correlation_mismatch', $result['error_code']);
        self::assertSame('indeterminate', $this->repository->findByUuid(self::OPERATION_UUID)['remote_status']);
        self::assertSame(1, $providerCalls);
        self::assertSame(0, $binderCalls);
    }

    public function testSuccessfulReplayRequiresMatchingIncomingProviderId(): void
    {
        $providerCalls = 0;
        $binderCalls = 0;
        $externalProviderId = null;
        $coordinator = $this->coordinator(
            static function () use (&$providerCalls): array {
                $providerCalls++;
                return self::approvedResponse();
            },
            static function (array $identity, string $providerId) use (&$binderCalls, &$externalProviderId): array {
                unset($identity);
                $binderCalls++;
                $externalProviderId = $providerId;
                return ['bound' => true, 'provider_transaction_id' => $providerId];
            },
            null,
            static function () use (&$externalProviderId): array {
                return null === $externalProviderId
                    ? ['status' => 'unbound', 'provider_transaction_id' => null]
                    : ['status' => 'bound', 'provider_transaction_id' => $externalProviderId];
            }
        );
        $first = $coordinator->execute($this->transaction(), 'card-token-secret');

        $same = $coordinator->execute($this->transaction(), '', '51177123');
        $withoutIncomingProof = $coordinator->execute($this->transaction(), '');
        $mismatch = $coordinator->execute($this->transaction(), '', '51177999');

        self::assertSame('succeeded', $first['status']);
        self::assertSame('succeeded', $same['status']);
        self::assertSame('succeeded', $withoutIncomingProof['status'], 'Browser replay may rely on the durable provider ID.');
        self::assertSame('attention_required', $mismatch['status']);
        self::assertSame('provider_id_mismatch', $mismatch['error_code']);
        self::assertSame(1, $providerCalls);
        self::assertSame(1, $binderCalls);
    }

    #[DataProvider('appliedInspectorOutcomes')]
    public function testAppliedReplayStillRequiresExactInspectorProof(array $postBindInspection, string $expectedStatus, ?string $expectedError): void
    {
        $bound = false;
        $coordinator = $this->coordinator(
            static fn (): array => self::approvedResponse(),
            static function (array $identity, string $providerId) use (&$bound): array {
                unset($identity);
                $bound = true;
                return ['bound' => true, 'provider_transaction_id' => $providerId];
            },
            null,
            static function () use (&$bound, $postBindInspection): array {
                return $bound
                    ? $postBindInspection
                    : ['status' => 'unbound', 'provider_transaction_id' => null];
            }
        );
        $first = $coordinator->execute($this->transaction(), 'card-token-secret');

        $replay = $coordinator->execute($this->transaction(), 'card-token-secret');

        self::assertSame('succeeded', $first['status']);
        self::assertSame($expectedStatus, $replay['status']);
        self::assertSame($expectedError, $replay['error_code']);
    }

    public static function appliedInspectorOutcomes(): iterable
    {
        yield 'same exact provider id' => [
            ['status' => 'bound', 'provider_transaction_id' => '51177123'],
            'succeeded',
            null,
        ];
        yield 'different provider id' => [
            ['status' => 'mismatch', 'provider_transaction_id' => '51177999'],
            'attention_required',
            'provider_id_mismatch',
        ];
        yield 'unknown inspector response' => [
            ['status' => 'bound'],
            'attention_required',
            'local_inspection_unknown',
        ];
    }

    #[DataProvider('immutableIdentityDrift')]
    public function testImmutableIdentityDriftIsDuplicateAttentionNotAnotherCharge(array $changes): void
    {
        $providerCalls = 0;
        $coordinator = $this->coordinator(
            static function () use (&$providerCalls): array {
                $providerCalls++;
                return self::declinedResponse();
            },
            static fn (): array => ['bound' => true, 'provider_transaction_id' => '51177123']
        );
        $coordinator->execute($this->transaction(), 'card-token-secret');

        $result = $coordinator->execute($this->transaction($changes), 'new-card-token');

        self::assertSame('attention_required', $result['status']);
        self::assertSame('operation_identity_mismatch', $result['error_code']);
        self::assertSame(1, $providerCalls);
        self::assertCount(1, $this->database->allRows());
    }

    public static function immutableIdentityDrift(): iterable
    {
        yield 'amount' => [['amount' => 2200]];
        yield 'gateway' => [['gateway' => 'ys_helcim']];
        yield 'order' => [['order_id' => 999]];
        yield 'transaction uuid' => [['transaction_uuid' => 'drifted-uuid']];
        yield 'currency' => [['currency' => 'CAD']];
        yield 'mode' => [['payment_mode' => 'live']];
    }

    /** @param callable $provider @param callable $binder */
    private function coordinator(
        callable $provider,
        callable $binder,
        ?callable $uuidFactory = null,
        ?callable $inspector = null,
        ?callable $clock = null
    ): YSHelcimPurchaseCoordinator
    {
        return new YSHelcimPurchaseCoordinator(
            $this->repository,
            $provider,
            $binder,
            $inspector ?? static fn (): array => ['status' => 'unbound', 'provider_transaction_id' => null],
            $uuidFactory ?? static fn (): string => self::OPERATION_UUID,
            $clock
        );
    }

    /** @return array<string, mixed> */
    private function transaction(array $changes = []): array
    {
        return array_replace(
            [
                'gateway' => 'ys_helcim_js',
                'order_id' => 10,
                'transaction_id' => 20,
                'transaction_uuid' => 'fc-transaction-123',
                'amount' => 2100,
                'currency' => 'USD',
                'payment_mode' => 'test',
            ],
            $changes
        );
    }

    /** @return array<string, mixed> */
    private static function approvedResponse(string $providerId = '51177123'): array
    {
        return [
            'outcome' => 'succeeded',
            'transaction' => [
                'status' => 'APPROVED',
                'type' => 'purchase',
                'transactionId' => $providerId,
                'amount' => '21.00',
                'currency' => 'USD',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function declinedResponse(): array
    {
        return [
            'outcome' => 'declined',
            'definitive' => true,
            'transaction' => [
                'status' => 'DECLINED',
                'type' => 'purchase',
                'amount' => '21.00',
                'currency' => 'USD',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function correlatedProof(array $proof, string $operationUuid): array
    {
        return ['operation_correlation' => $operationUuid] + $proof;
    }
}
