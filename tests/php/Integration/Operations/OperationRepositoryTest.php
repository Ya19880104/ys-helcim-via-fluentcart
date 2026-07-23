<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Integration\Operations;

use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimIdempotency;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationRepository;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundPayload;
use YangSheep\Helcim\FluentCart\Security\YSHelcimSensitiveEnvelope;
use YangSheep\Helcim\FluentCart\Tests\Doubles\FakeWpdb;

final class OperationRepositoryTest extends TestCase
{
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

    public function testStableActiveScopeAllowsOneClaimantAcrossDifferentOperations(): void
    {
        $first = $this->repository->create($this->operation(1, 'purchase:fc-123'));
        $second = $this->repository->create($this->operation(2, 'purchase:fc-123'));

        self::assertIsArray($first);
        self::assertInstanceOf(\WP_Error::class, $second);
        self::assertSame('ys_helcim_scope_busy', $second->get_error_code());
        self::assertCount(1, $this->database->allRows());
    }

    public function testCreatedOperationCanBeClaimedExactlyOnce(): void
    {
        $operation = $this->operation(1, 'purchase:fc-123');
        $this->repository->create($operation);

        self::assertTrue($this->repository->claimRemoteProcessing($operation['operation_uuid']));
        self::assertFalse($this->repository->claimRemoteProcessing($operation['operation_uuid']));
        self::assertSame('processing', $this->repository->findByUuid($operation['operation_uuid'])['remote_status']);
    }

    public function testStrictUuidReadDistinguishesMissingRowsFromDatabaseFailure(): void
    {
        $operation = $this->operation(1, 'purchase:fc-123');

        self::assertNull($this->repository->findByUuidStrict($operation['operation_uuid']));
        self::assertIsArray($this->repository->create($operation));
        self::assertIsArray($this->repository->findByUuidStrict($operation['operation_uuid']));

        $this->database->failNextLookup = true;
        $failure = $this->repository->findByUuidStrict($operation['operation_uuid']);
        self::assertInstanceOf(\WP_Error::class, $failure);
        self::assertSame('ys_helcim_journal_unavailable', $failure->get_error_code());
    }

    public function testIndeterminateOperationKeepsScopeAndCannotRestart(): void
    {
        $operation = $this->operation(1, 'refund:parent-99');
        $this->repository->create($operation);
        $this->repository->claimRemoteProcessing($operation['operation_uuid']);

        self::assertTrue($this->repository->transitionRemote($operation['operation_uuid'], 'processing', 'indeterminate'));
        self::assertFalse($this->repository->claimRemoteProcessing($operation['operation_uuid']));
        self::assertInstanceOf(
            \WP_Error::class,
            $this->repository->create($this->operation(2, 'refund:parent-99'))
        );
    }

    public function testRemoteSuccessAndLocalFailureRemainRetryableWithoutProviderRestart(): void
    {
        $operation = $this->operation(1, 'purchase:fc-123');
        $this->repository->create($operation);
        $this->repository->claimRemoteProcessing($operation['operation_uuid']);

        self::assertTrue(
            $this->repository->transitionRemote(
                $operation['operation_uuid'],
                'processing',
                'succeeded',
                ['vendor_transaction_id' => '51177123']
            )
        );
        self::assertTrue($this->repository->claimLocalApplying($operation['operation_uuid'], 'pending'));
        self::assertFalse($this->repository->claimLocalApplying($operation['operation_uuid'], 'pending'));
        self::assertTrue($this->repository->transitionLocal($operation['operation_uuid'], 'applying', 'failed'));
        self::assertFalse($this->repository->claimRemoteProcessing($operation['operation_uuid']));
        self::assertInstanceOf(
            \WP_Error::class,
            $this->repository->create($this->operation(2, 'purchase:fc-123'))
        );

        self::assertTrue($this->repository->claimLocalApplying($operation['operation_uuid'], 'failed'));
        self::assertTrue($this->repository->transitionLocal($operation['operation_uuid'], 'applying', 'applied'));
        self::assertNull($this->repository->findByUuid($operation['operation_uuid'])['active_scope_key']);
        self::assertIsArray($this->repository->create($this->operation(2, 'purchase:fc-123')));
    }

    public function testProviderReceiptIsReservedSiteWideByTheDatabaseUniqueConstraint(): void
    {
        $first = $this->operation(91, 'purchase:first');
        $second = $this->operation(92, 'purchase:second');
        $second['payment_mode'] = 'live';
        $second['idempotency_key'] = YSHelcimIdempotency::generate(
            'purchase',
            $second['transaction_uuid'],
            $second['amount'],
            'live',
            $second['operation_uuid']
        );
        self::assertIsArray($this->repository->create($first));
        self::assertIsArray($this->repository->create($second));
        self::assertTrue($this->repository->claimRemoteProcessing($first['operation_uuid']));
        self::assertTrue($this->repository->claimRemoteProcessing($second['operation_uuid']));
        self::assertTrue($this->repository->transitionRemote(
            $first['operation_uuid'],
            'processing',
            'succeeded',
            ['vendor_transaction_id' => '51177123']
        ));

        $duplicate = $this->repository->transitionRemote(
            $second['operation_uuid'],
            'processing',
            'succeeded',
            ['vendor_transaction_id' => '51177123']
        );

        self::assertInstanceOf(\WP_Error::class, $duplicate);
        self::assertSame('ys_helcim_journal_unavailable', $duplicate->get_error_code());
        self::assertSame('processing', $this->repository->findByUuid($second['operation_uuid'])['remote_status']);
        self::assertNull($this->repository->findByUuid($second['operation_uuid'])['vendor_transaction_id']);
    }

    public function testLocalClaimTimestampIsDurableAndClearedOnTerminalLocalState(): void
    {
        $now = '2026-07-21 00:00:00';
        $repository = new YSHelcimOperationRepository(
            $this->database,
            static function () use (&$now): string {
                return $now;
            }
        );
        $operation = $this->operation(1, 'purchase:fc-123');
        $repository->create($operation);
        $repository->claimRemoteProcessing($operation['operation_uuid']);
        $repository->transitionRemote(
            $operation['operation_uuid'],
            'processing',
            'succeeded',
            ['vendor_transaction_id' => '51177123']
        );

        self::assertTrue($repository->claimLocalApplying($operation['operation_uuid'], 'pending'));
        self::assertSame($now, $repository->findByUuid($operation['operation_uuid'])['local_claimed_at']);

        $now = '2026-07-21 00:01:00';
        self::assertTrue($repository->transitionLocal($operation['operation_uuid'], 'applying', 'failed'));
        self::assertNull($repository->findByUuid($operation['operation_uuid'])['local_claimed_at']);
        self::assertTrue($repository->claimLocalApplying($operation['operation_uuid'], 'failed'));
        self::assertSame($now, $repository->findByUuid($operation['operation_uuid'])['local_claimed_at']);

        self::assertTrue($repository->transitionLocal($operation['operation_uuid'], 'applying', 'applied'));
        self::assertNull($repository->findByUuid($operation['operation_uuid'])['local_claimed_at']);
    }

    public function testOnlyAStaleApplyingClaimCanBeAtomicallyReclaimed(): void
    {
        $now = '2026-07-21 00:00:00';
        $repository = new YSHelcimOperationRepository(
            $this->database,
            static function () use (&$now): string {
                return $now;
            }
        );
        $operation = $this->operation(1, 'purchase:fc-123');
        $repository->create($operation);
        $repository->claimRemoteProcessing($operation['operation_uuid']);
        $repository->transitionRemote(
            $operation['operation_uuid'],
            'processing',
            'succeeded',
            ['vendor_transaction_id' => '51177123']
        );
        $repository->claimLocalApplying($operation['operation_uuid'], 'pending');

        $now = '2026-07-21 00:04:00';
        self::assertFalse($repository->reclaimStaleLocalApplying(
            $operation['operation_uuid'],
            '2026-07-20 23:59:59'
        ));
        self::assertSame('2026-07-21 00:00:00', $repository->findByUuid($operation['operation_uuid'])['local_claimed_at']);

        $now = '2026-07-21 00:10:00';
        self::assertTrue($repository->reclaimStaleLocalApplying(
            $operation['operation_uuid'],
            '2026-07-21 00:05:00'
        ));
        self::assertSame($now, $repository->findByUuid($operation['operation_uuid'])['local_claimed_at']);
        self::assertFalse($repository->reclaimStaleLocalApplying(
            $operation['operation_uuid'],
            '2026-07-21 00:05:00'
        ));
    }

    public function testRolledBackLocalFailureCanBePersistedOutsideTheFailedTransaction(): void
    {
        $operation = $this->operation(1, 'refund:parent-99');
        $this->repository->create($operation);
        $this->repository->claimRemoteProcessing($operation['operation_uuid']);
        $this->repository->transitionRemote(
            $operation['operation_uuid'],
            'processing',
            'succeeded',
            ['vendor_transaction_id' => '51177123']
        );

        self::assertTrue($this->repository->recordLocalFailure(
            $operation['operation_uuid'],
            'ys_helcim_accounting_drift',
            'database password=must-not-survive'
        ));
        self::assertFalse($this->repository->recordLocalFailure(
            $operation['operation_uuid'],
            'ys_helcim_accounting_drift',
            'duplicate failure'
        ));

        $stored = $this->repository->findByUuid($operation['operation_uuid']);
        self::assertSame('failed', $stored['local_status']);
        self::assertSame('ys_helcim_accounting_drift', $stored['local_error_code']);
        self::assertStringNotContainsString('must-not-survive', $stored['local_error_message']);
        self::assertNotNull($stored['active_scope_key']);
        self::assertTrue($this->repository->claimLocalApplying($operation['operation_uuid'], 'failed'));
    }

    public function testDefiniteFailureReleasesScopeButCannotRestartTerminalOperation(): void
    {
        $operation = $this->operation(1, 'refund:parent-99');
        $operation['encrypted_material'] = YSHelcimSensitiveEnvelope::encrypt('encrypted-card-token');
        $operation['material_expires_at'] = '2026-07-21 00:10:00';
        $this->repository->create($operation);
        $this->repository->claimRemoteProcessing($operation['operation_uuid']);

        self::assertTrue(
            $this->repository->transitionRemote(
                $operation['operation_uuid'],
                'processing',
                'failed',
                [
                    'error_code' => 'provider_error',
                    'error_message' => '<b>Declined</b> card 4111111111111111 api-token=secret-value',
                ]
            )
        );
        $failed = $this->repository->findByUuid($operation['operation_uuid']);
        self::assertNull($failed['active_scope_key']);
        self::assertNull($failed['encrypted_material']);
        self::assertNull($failed['material_expires_at']);
        self::assertStringNotContainsString('4111111111111111', $failed['remote_error_message']);
        self::assertStringNotContainsString('secret-value', $failed['remote_error_message']);
        self::assertFalse($this->repository->claimRemoteProcessing($operation['operation_uuid']));
        self::assertIsArray($this->repository->create($this->operation(2, 'refund:parent-99')));
    }

    public function testStorageFailureIsNotMisreportedAsAnOperationConflict(): void
    {
        $this->database->failNextInsert = true;

        $result = $this->repository->create($this->operation(1, 'purchase:fc-123'));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_journal_unavailable', $result->get_error_code());
    }

    public function testUpdateStorageFailureIsDistinctFromLostClaim(): void
    {
        $operation = $this->operation(1, 'purchase:fc-123');
        $this->repository->create($operation);
        $this->database->failNextUpdate = true;

        $result = $this->repository->claimRemoteProcessing($operation['operation_uuid']);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_journal_unavailable', $result->get_error_code());
        self::assertSame('created', $this->repository->findByUuid($operation['operation_uuid'])['remote_status']);
    }

    public function testConfirmTokenIsConsumedExactlyOnce(): void
    {
        $rawToken = 'one-time-operation-confirm-token';
        $operation = $this->operation(1, 'purchase:fc-123');
        $operation['confirm_token_hash'] = hash('sha256', $rawToken);
        $operation['confirm_token_expires_at'] = '2026-07-21 00:10:00';
        $this->repository->create($operation);

        self::assertFalse($this->repository->consumeConfirmToken($operation['operation_uuid'], 'wrong-token'));
        self::assertTrue($this->repository->consumeConfirmToken($operation['operation_uuid'], $rawToken));
        self::assertFalse($this->repository->consumeConfirmToken($operation['operation_uuid'], $rawToken));
        $stored = $this->repository->findByUuid($operation['operation_uuid']);
        self::assertNull($stored['confirm_token_hash']);
        self::assertNull($stored['confirm_token_expires_at']);
    }

    public function testConfirmTokenHashAndExpiryMustBeStoredTogether(): void
    {
        $operation = $this->operation(1, 'purchase:fc-123');
        $operation['confirm_token_hash'] = hash('sha256', 'token');

        $result = $this->repository->create($operation);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_invalid_operation', $result->get_error_code());
    }

    public function testSuccessfulInsertWithFailedReadbackReturnsStorageError(): void
    {
        $this->database->failNextLookup = true;

        $result = $this->repository->create($this->operation(1, 'purchase:fc-123'));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_journal_unavailable', $result->get_error_code());
    }

    public function testEmptyOptionalCorrelationIsPersistedAsNull(): void
    {
        $first = $this->operation(1, 'purchase:first');
        $first['provider_correlation_id'] = '';
        $second = $this->operation(2, 'purchase:second');
        $second['provider_correlation_id'] = '';

        $createdFirst = $this->repository->create($first);
        $createdSecond = $this->repository->create($second);

        self::assertIsArray($createdFirst);
        self::assertIsArray($createdSecond);
        self::assertNull($createdFirst['provider_correlation_id']);
        self::assertNull($createdSecond['provider_correlation_id']);
    }

    public function testSensitiveMaterialRequiresEnvelopeAndExpiryTogether(): void
    {
        $plaintext = $this->operation(1, 'purchase:first');
        $plaintext['encrypted_material'] = 'plain-card-token';
        $plaintext['material_expires_at'] = '2026-07-21 00:10:00';

        $missingExpiry = $this->operation(2, 'purchase:second');
        $missingExpiry['encrypted_material'] = YSHelcimSensitiveEnvelope::encrypt('ciphertext');

        foreach ([$plaintext, $missingExpiry] as $invalid) {
            $result = $this->repository->create($invalid);
            self::assertInstanceOf(\WP_Error::class, $result);
            self::assertSame('ys_helcim_invalid_operation', $result->get_error_code());
        }
    }

    public function testExpiredSensitiveMaterialIsPurgedWithoutReleasingProcessingScope(): void
    {
        $now = '2026-07-21 00:00:00';
        $repository = new YSHelcimOperationRepository(
            $this->database,
            static function () use (&$now): string {
                return $now;
            }
        );
        $expired = $this->operation(1, 'purchase:first');
        $expired['encrypted_material'] = YSHelcimSensitiveEnvelope::encrypt('expired-token');
        $expired['material_expires_at'] = '2026-07-21 00:05:00';
        $fresh = $this->operation(2, 'purchase:second');
        $fresh['encrypted_material'] = YSHelcimSensitiveEnvelope::encrypt('fresh-token');
        $fresh['material_expires_at'] = '2026-07-21 00:20:00';
        $repository->create($expired);
        $repository->claimRemoteProcessing($expired['operation_uuid']);
        $repository->create($fresh);
        $now = '2026-07-21 00:10:00';

        self::assertIsArray($repository->create($this->operation(3, 'purchase:third')));
        $expiredStored = $repository->findByUuid($expired['operation_uuid']);
        $freshStored = $repository->findByUuid($fresh['operation_uuid']);
        self::assertNull($expiredStored['encrypted_material']);
        self::assertNull($expiredStored['material_expires_at']);
        self::assertNotNull($expiredStored['active_scope_key']);
        self::assertNotNull($freshStored['encrypted_material']);
    }

    public function testStaleCreatedRefundExpiresAndReleasesItsScopeBeforeMaterialPurge(): void
    {
        $now = '2026-07-21 00:00:00';
        $repository = new YSHelcimOperationRepository(
            $this->database,
            static function () use (&$now): string {
                return $now;
            }
        );
        $abandoned = $this->operation(1, 'refund:parent-99', 'refund');
        $abandoned['encrypted_material'] = YSHelcimSensitiveEnvelope::encrypt('refund-context');
        $abandoned['material_expires_at'] = '2026-07-21 00:05:00';
        self::assertIsArray($repository->create($abandoned));

        $now = '2026-07-21 00:10:00';
        $replacement = $this->operation(2, 'refund:parent-99', 'refund');
        $replacement['encrypted_material'] = YSHelcimSensitiveEnvelope::encrypt('replacement-context');
        $replacement['material_expires_at'] = '2026-07-21 00:20:00';

        self::assertIsArray($repository->create($replacement));
        $expired = $repository->findByUuid($abandoned['operation_uuid']);
        self::assertSame('expired', $expired['remote_status']);
        self::assertSame('failed', $expired['local_status']);
        self::assertNull($expired['active_scope_key']);
        self::assertNull($expired['encrypted_material']);
        self::assertNull($expired['material_expires_at']);
        self::assertSame('ys_helcim_operation_expired_before_claim', $expired['remote_error_code']);
    }

    public function testStaleCreatedPurchaseWithoutSensitiveMaterialAllowsANewAttempt(): void
    {
        $now = '2026-07-21 00:00:00';
        $repository = new YSHelcimOperationRepository(
            $this->database,
            static function () use (&$now): string {
                return $now;
            }
        );
        $abandoned = $this->operation(1, 'purchase:fc-123');
        self::assertIsArray($repository->create($abandoned));

        $now = '2026-07-21 00:06:00';

        self::assertIsArray($repository->create($this->operation(2, 'purchase:fc-123')));
        $expired = $repository->findByUuid($abandoned['operation_uuid']);
        self::assertSame('expired', $expired['remote_status']);
        self::assertSame('failed', $expired['local_status']);
        self::assertNull($expired['active_scope_key']);
    }

    public function testStaleProcessingOperationNeverAutoReleasesItsScope(): void
    {
        $now = '2026-07-21 00:00:00';
        $repository = new YSHelcimOperationRepository(
            $this->database,
            static function () use (&$now): string {
                return $now;
            }
        );
        $processing = $this->operation(1, 'purchase:fc-123');
        self::assertIsArray($repository->create($processing));
        self::assertTrue($repository->claimRemoteProcessing($processing['operation_uuid']));

        $now = '2026-07-21 02:00:00';
        $replacement = $repository->create($this->operation(2, 'purchase:fc-123'));

        self::assertInstanceOf(\WP_Error::class, $replacement);
        self::assertSame('ys_helcim_scope_busy', $replacement->get_error_code());
        $stored = $repository->findByUuid($processing['operation_uuid']);
        self::assertSame('processing', $stored['remote_status']);
        self::assertNotNull($stored['active_scope_key']);
    }

    public function testStaleRefundProcessingIsPromotedToIndeterminateWithoutReleasingItsScope(): void
    {
        $now = '2026-07-21 00:00:00';
        $repository = new YSHelcimOperationRepository(
            $this->database,
            static function () use (&$now): string {
                return $now;
            }
        );
        $processing = $this->operation(1, 'refund:parent-99', 'refund');
        $processing['encrypted_material'] = YSHelcimSensitiveEnvelope::encrypt('refund-context');
        $processing['material_expires_at'] = '2026-07-21 00:20:00';
        self::assertIsArray($repository->create($processing));
        self::assertTrue($repository->claimRemoteProcessing($processing['operation_uuid']));
        $claimedScope = $repository->findByUuid($processing['operation_uuid'])['active_scope_key'];

        $now = '2026-07-21 00:06:00';
        self::assertSame(1, $repository->promoteStaleRefundProcessing($processing['operation_uuid']));

        $stored = $repository->findByUuid($processing['operation_uuid']);
        self::assertSame('indeterminate', $stored['remote_status']);
        self::assertSame('pending', $stored['local_status']);
        self::assertSame($claimedScope, $stored['active_scope_key']);
        self::assertSame('ys_helcim_provider_result_unpersisted', $stored['remote_error_code']);
        self::assertNull($stored['encrypted_material']);
        self::assertNull($stored['material_expires_at']);
    }

    public function testFreshRefundProcessingAndPurchaseProcessingAreNeverPromoted(): void
    {
        $now = '2026-07-21 00:00:00';
        $repository = new YSHelcimOperationRepository(
            $this->database,
            static function () use (&$now): string {
                return $now;
            }
        );
        $refund = $this->operation(1, 'refund:first', 'refund');
        $purchase = $this->operation(2, 'purchase:second', 'purchase');
        self::assertIsArray($repository->create($refund));
        self::assertIsArray($repository->create($purchase));
        self::assertTrue($repository->claimRemoteProcessing($refund['operation_uuid']));
        self::assertTrue($repository->claimRemoteProcessing($purchase['operation_uuid']));

        $now = '2026-07-21 00:04:59';
        self::assertSame(0, $repository->promoteStaleRefundProcessing($refund['operation_uuid']));
        $now = '2026-07-21 02:00:00';
        self::assertSame(0, $repository->promoteStaleRefundProcessing($purchase['operation_uuid']));
        self::assertSame('processing', $repository->findByUuid($refund['operation_uuid'])['remote_status']);
        self::assertSame('processing', $repository->findByUuid($purchase['operation_uuid'])['remote_status']);
    }

    public function testOrderRecoveryPromotesOnlyStaleRefundClaimsForThatOrder(): void
    {
        $now = '2026-07-21 00:00:00';
        $repository = new YSHelcimOperationRepository(
            $this->database,
            static function () use (&$now): string {
                return $now;
            }
        );
        $first = $this->operation(1, 'refund:first', 'refund');
        $second = $this->operation(2, 'refund:second', 'refund');
        $second['order_id'] = 11;
        self::assertIsArray($repository->create($first));
        self::assertIsArray($repository->create($second));
        self::assertTrue($repository->claimRemoteProcessing($first['operation_uuid']));
        self::assertTrue($repository->claimRemoteProcessing($second['operation_uuid']));

        $now = '2026-07-21 00:06:00';
        self::assertSame(1, $repository->promoteStaleRefundProcessingForOrder(10));
        self::assertSame('indeterminate', $repository->findByUuid($first['operation_uuid'])['remote_status']);
        self::assertSame('processing', $repository->findByUuid($second['operation_uuid'])['remote_status']);
    }

    public function testRemoteRecoveryClearsStaleRemoteError(): void
    {
        $operation = $this->operation(1, 'purchase:first');
        $this->repository->create($operation);
        $this->repository->claimRemoteProcessing($operation['operation_uuid']);
        $this->repository->transitionRemote(
            $operation['operation_uuid'],
            'processing',
            'indeterminate',
            ['error_code' => 'timeout', 'error_message' => 'Provider response lost']
        );

        self::assertTrue(
            $this->repository->transitionRemote(
                $operation['operation_uuid'],
                'indeterminate',
                'succeeded',
                ['vendor_transaction_id' => '51177123']
            )
        );
        $recovered = $this->repository->findByUuid($operation['operation_uuid']);
        self::assertNull($recovered['remote_error_code']);
        self::assertNull($recovered['remote_error_message']);
    }

    public function testRecordedRefundKeepsScopeUntilRequiredEffectsAreApplied(): void
    {
        $operation = $this->operation(1, 'refund-order:10', 'refund');
        $created = $this->repository->create($operation);
        self::assertIsArray($created);
        self::assertSame('51177061', $created['source_vendor_transaction_id']);
        $this->repository->claimRemoteProcessing($operation['operation_uuid']);
        $this->repository->transitionRemote(
            $operation['operation_uuid'],
            'processing',
            'succeeded',
            ['vendor_transaction_id' => '51177123']
        );
        self::assertTrue($this->repository->claimLocalApplying($operation['operation_uuid'], 'pending'));

        self::assertTrue($this->repository->transitionLocal(
            $operation['operation_uuid'],
            'applying',
            'recorded',
            ['local_transaction_id' => 700]
        ));
        $recorded = $this->repository->findByUuid($operation['operation_uuid']);
        self::assertSame('recorded', $recorded['local_status']);
        self::assertSame(700, $recorded['local_transaction_id']);
        self::assertNotNull($recorded['active_scope_key']);
        self::assertNotNull($recorded['local_recorded_at']);

        self::assertTrue($this->repository->transitionLocal($operation['operation_uuid'], 'recorded', 'applied'));
        $applied = $this->repository->findByUuid($operation['operation_uuid']);
        self::assertSame('applied', $applied['local_status']);
        self::assertNull($applied['active_scope_key']);
        self::assertNotNull($applied['local_applied_at']);
    }

    public function testRefundFailureAtomicallyHandsScopeToReverseChild(): void
    {
        $parent = $this->operation(1, 'refund:parent-99', 'refund');
        $child = $this->operation(2, 'refund:parent-99', 'reverse');
        $child['parent_operation_uuid'] = $parent['operation_uuid'];
        $this->repository->create($parent);
        $this->repository->claimRemoteProcessing($parent['operation_uuid']);

        $createdChild = $this->repository->handoffRemoteFailureToChild(
            $parent['operation_uuid'],
            'processing',
            $child,
            ['error_code' => 'open_batch_requires_reverse', 'error_message' => 'Use reverse']
        );

        self::assertIsArray($createdChild);
        self::assertSame('reverse', $createdChild['operation_type']);
        self::assertSame($parent['operation_uuid'], $createdChild['parent_operation_uuid']);
        self::assertNotSame($parent['idempotency_key'], $createdChild['idempotency_key']);
        self::assertSame('failed', $this->repository->findByUuid($parent['operation_uuid'])['remote_status']);
        self::assertNull($this->repository->findByUuid($parent['operation_uuid'])['active_scope_key']);
        self::assertSame($createdChild['operation_uuid'], $this->repository->findActiveByScope('refund:parent-99')['operation_uuid']);
        self::assertSame(
            $createdChild['operation_uuid'],
            $this->repository->findChildByParent($parent['operation_uuid'], 'reverse')['operation_uuid']
        );
    }

    public function testOperationTypeIsStoredCanonically(): void
    {
        $created = $this->repository->create($this->operation(1, 'refund:parent-99', 'REFUND'));

        self::assertIsArray($created);
        self::assertSame('refund', $created['operation_type']);
    }

    public function testReverseCannotBeCreatedOutsideAtomicHandoff(): void
    {
        $reverse = $this->operation(1, 'refund:parent-99', 'reverse');
        $reverse['parent_operation_uuid'] = '00000000-0000-4000-8000-000000000009';

        $result = $this->repository->create($reverse);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_invalid_operation', $result->get_error_code());
    }

    public function testIndeterminateRefundCannotFallbackToReverse(): void
    {
        $parent = $this->operation(1, 'refund:parent-99', 'refund');
        $child = $this->operation(2, 'refund:parent-99', 'reverse');
        $child['parent_operation_uuid'] = $parent['operation_uuid'];
        $this->repository->create($parent);
        $this->repository->claimRemoteProcessing($parent['operation_uuid']);
        $this->repository->transitionRemote($parent['operation_uuid'], 'processing', 'indeterminate');

        $result = $this->repository->handoffRemoteFailureToChild(
            $parent['operation_uuid'],
            'indeterminate',
            $child
        );

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('indeterminate', $this->repository->findByUuid($parent['operation_uuid'])['remote_status']);
        self::assertCount(1, $this->database->allRows());
    }

    public function testFailedReverseChildInsertRollsBackParentScopeRelease(): void
    {
        $parent = $this->operation(1, 'refund:parent-99', 'refund');
        $child = $this->operation(2, 'refund:parent-99', 'reverse');
        $child['parent_operation_uuid'] = $parent['operation_uuid'];
        $this->repository->create($parent);
        $this->repository->claimRemoteProcessing($parent['operation_uuid']);
        $this->database->failNextInsert = true;

        $result = $this->repository->handoffRemoteFailureToChild(
            $parent['operation_uuid'],
            'processing',
            $child,
            ['error_code' => 'open_batch_requires_reverse']
        );

        self::assertInstanceOf(\WP_Error::class, $result);
        $restoredParent = $this->repository->findByUuid($parent['operation_uuid']);
        self::assertSame('processing', $restoredParent['remote_status']);
        self::assertNotNull($restoredParent['active_scope_key']);
        self::assertCount(1, $this->database->allRows());
    }

    public function testUuidKeyAndProviderCorrelationAreUnique(): void
    {
        $first = $this->operation(1, 'purchase:one');
        $this->repository->create($first);
        $this->repository->transitionRemote($first['operation_uuid'], 'created', 'failed');

        $sameUuid = $this->operation(1, 'purchase:two');
        $sameKey = $this->operation(2, 'purchase:two');
        $sameKey['idempotency_key'] = $first['idempotency_key'];
        $sameCorrelation = $this->operation(3, 'purchase:three');
        $sameCorrelation['provider_correlation_id'] = $first['provider_correlation_id'];

        foreach (
            [
                'ys_helcim_operation_conflict' => [$sameUuid, $sameCorrelation],
                'ys_helcim_invalid_operation' => [$sameKey],
            ] as $expectedCode => $conflicts
        ) {
            foreach ($conflicts as $conflict) {
            $result = $this->repository->create($conflict);
            self::assertInstanceOf(\WP_Error::class, $result);
                self::assertSame($expectedCode, $result->get_error_code());
            }
        }
    }

    public function testHostedRecoveryScanReturnsOnlyExpiredActiveHostedPurchases(): void
    {
        $due = $this->operation(81, 'purchase:due');
        $due['provider_correlation_id'] = $due['operation_uuid'];
        self::assertIsArray($this->repository->create($due));
        self::assertTrue($this->repository->claimRemoteProcessing($due['operation_uuid']));
        $this->database->update('wp_ys_helcim_operations', ['created_at' => '2026-07-20 22:00:00'], ['operation_uuid' => $due['operation_uuid']]);

        $recent = $this->operation(82, 'purchase:recent');
        $recent['provider_correlation_id'] = $recent['operation_uuid'];
        self::assertIsArray($this->repository->create($recent));
        self::assertTrue($this->repository->claimRemoteProcessing($recent['operation_uuid']));

        $inline = $this->operation(83, 'purchase:inline');
        $inline['gateway'] = 'ys_helcim_js';
        $inline['provider_correlation_id'] = $inline['operation_uuid'];
        self::assertIsArray($this->repository->create($inline));
        self::assertTrue($this->repository->claimRemoteProcessing($inline['operation_uuid']));
        $this->database->update('wp_ys_helcim_operations', ['created_at' => '2026-07-20 22:00:00'], ['operation_uuid' => $inline['operation_uuid']]);

		$rows = $this->repository->findHostedPurchasesNeedingRecovery(
			'2026-07-20 23:00:00',
			'2026-07-21 00:00:00',
			'2026-07-20 23:55:00',
			7,
			10
		);

        self::assertIsArray($rows);
        self::assertSame([$due['operation_uuid']], array_column($rows, 'operation_uuid'));

        $this->database->failNextResults = true;
		$failed = $this->repository->findHostedPurchasesNeedingRecovery(
			'2026-07-20 23:00:00',
			'2026-07-21 00:00:00',
			'2026-07-20 23:55:00',
			7,
			10
		);
        self::assertInstanceOf(\WP_Error::class, $failed);
        self::assertSame('ys_helcim_journal_unavailable', $failed->get_error_code());
    }

	public function testInlineRecoveryScanReturnsOnlyExpiredActiveInlinePurchases(): void
	{
		$inline = $this->operation(130, 'purchase:inline-due');
		$inline['gateway'] = 'ys_helcim_js';
		$inline['provider_correlation_id'] = $inline['operation_uuid'];
		self::assertIsArray($this->repository->create($inline));
		self::assertTrue($this->repository->claimRemoteProcessing($inline['operation_uuid']));
		$this->database->update(
			'wp_ys_helcim_operations',
			['created_at' => '2026-07-20 22:00:00'],
			['operation_uuid' => $inline['operation_uuid']]
		);

		$hosted = $this->operation(131, 'purchase:hosted-due');
		$hosted['provider_correlation_id'] = $hosted['operation_uuid'];
		self::assertIsArray($this->repository->create($hosted));
		self::assertTrue($this->repository->claimRemoteProcessing($hosted['operation_uuid']));
		$this->database->update(
			'wp_ys_helcim_operations',
			['created_at' => '2026-07-20 22:00:00'],
			['operation_uuid' => $hosted['operation_uuid']]
		);

		$rows = $this->repository->findPurchasesNeedingRecovery(
			'ys_helcim_js',
			'2026-07-20 23:00:00',
			'2026-07-21 00:00:00',
			'2026-07-20 23:55:00',
			7,
			10
		);

		self::assertIsArray($rows);
		self::assertSame([$inline['operation_uuid']], array_column($rows, 'operation_uuid'));
	}

	public function testInlineRecoveryClaimIsDurableSingleClaimantAndGatewayBound(): void
	{
		$operation = $this->operation(132, 'purchase:inline-claim');
		$operation['gateway'] = 'ys_helcim_js';
		$operation['provider_correlation_id'] = $operation['operation_uuid'];
		self::assertIsArray($this->repository->create($operation));
		self::assertTrue($this->repository->claimRemoteProcessing($operation['operation_uuid']));

		self::assertFalse($this->repository->claimPurchaseRecovery(
			$operation['operation_uuid'],
			'ys_helcim',
			'2026-07-21 00:00:00',
			'2026-07-20 23:55:00',
			'2026-07-21 00:02:00',
			7
		));
		self::assertTrue($this->repository->claimPurchaseRecovery(
			$operation['operation_uuid'],
			'ys_helcim_js',
			'2026-07-21 00:00:00',
			'2026-07-20 23:55:00',
			'2026-07-21 00:02:00',
			7
		));
		self::assertFalse($this->repository->claimPurchaseRecovery(
			$operation['operation_uuid'],
			'ys_helcim_js',
			'2026-07-21 00:00:00',
			'2026-07-20 23:55:00',
			'2026-07-21 00:02:00',
			7
		));

		$claimed = $this->repository->findByUuid($operation['operation_uuid']);
		self::assertSame(1, (int) $claimed['recovery_attempt_count']);
		self::assertSame('2026-07-21 00:02:00', $claimed['next_recovery_at']);
	}

	public function testInlineRecoveryDeferIsDurableAndGatewayBound(): void
	{
		$operation = $this->operation(133, 'purchase:inline-defer');
		$operation['gateway'] = 'ys_helcim_js';
		$operation['provider_correlation_id'] = $operation['operation_uuid'];
		self::assertIsArray($this->repository->create($operation));
		self::assertTrue($this->repository->claimRemoteProcessing($operation['operation_uuid']));
		self::assertTrue($this->repository->claimPurchaseRecovery(
			$operation['operation_uuid'],
			'ys_helcim_js',
			'2026-07-21 00:00:00',
			'2026-07-20 23:55:00',
			'2026-07-21 00:02:00',
			7
		));

		self::assertFalse($this->repository->deferPurchaseRecovery(
			$operation['operation_uuid'],
			'ys_helcim',
			1,
			'2026-07-21 00:02:00',
			'2026-07-21 00:05:00',
			'provider_timeout',
			'Provider lookup timed out.'
		));
		self::assertTrue($this->repository->deferPurchaseRecovery(
			$operation['operation_uuid'],
			'ys_helcim_js',
			1,
			'2026-07-21 00:02:00',
			'2026-07-21 00:05:00',
			'provider_timeout',
			'Provider lookup timed out.'
		));

		$deferred = $this->repository->findByUuid($operation['operation_uuid']);
		self::assertSame(1, (int) $deferred['recovery_attempt_count']);
		self::assertSame('2026-07-21 00:05:00', $deferred['next_recovery_at']);
		self::assertSame('provider_timeout', $deferred['remote_error_code']);
	}

	public function testInlineAttentionQueryIncludesUnresolvedAndLocalBindingFailures(): void
	{
		$indeterminate = $this->operation(134, 'purchase:inline-attention-indeterminate');
		$indeterminate['gateway'] = 'ys_helcim_js';
		$indeterminate['provider_correlation_id'] = $indeterminate['operation_uuid'];
		self::assertIsArray($this->repository->create($indeterminate));
		self::assertTrue($this->repository->claimRemoteProcessing($indeterminate['operation_uuid']));
		self::assertTrue($this->repository->transitionRemote($indeterminate['operation_uuid'], 'processing', 'indeterminate'));

		$succeeded = $this->operation(135, 'purchase:inline-attention-local');
		$succeeded['gateway'] = 'ys_helcim_js';
		$succeeded['provider_correlation_id'] = $succeeded['operation_uuid'];
		self::assertIsArray($this->repository->create($succeeded));
		self::assertTrue($this->repository->claimRemoteProcessing($succeeded['operation_uuid']));
		self::assertTrue($this->repository->transitionRemote(
			$succeeded['operation_uuid'],
			'processing',
			'succeeded',
			['vendor_transaction_id' => '51178135']
		));

		$rows = $this->repository->findPurchasesNeedingAttention('ys_helcim_js', 10, 7);
		self::assertIsArray($rows);
		self::assertSame(
			[$indeterminate['operation_uuid'], $succeeded['operation_uuid']],
			array_column($rows, 'operation_uuid')
		);
	}

	public function testHostedRecoveryClaimAndBackoffAreDurableAndSingleClaimant(): void
	{
		$operation = $this->operation(84, 'purchase:hosted-claim');
		$operation['provider_correlation_id'] = $operation['operation_uuid'];
		self::assertIsArray($this->repository->create($operation));
		self::assertTrue($this->repository->claimRemoteProcessing($operation['operation_uuid']));
		$this->database->update(
			'wp_ys_helcim_operations',
			['created_at' => '2026-07-20 22:00:00'],
			['operation_uuid' => $operation['operation_uuid']]
		);

		self::assertTrue($this->repository->claimHostedRecovery(
			$operation['operation_uuid'],
			'2026-07-21 00:00:00',
			'2026-07-20 23:55:00',
			'2026-07-21 00:02:00',
			7
		));
		self::assertFalse($this->repository->claimHostedRecovery(
			$operation['operation_uuid'],
			'2026-07-21 00:00:00',
			'2026-07-20 23:55:00',
			'2026-07-21 00:02:00',
			7
		));

		$claimed = $this->repository->findByUuid($operation['operation_uuid']);
		self::assertSame(1, (int) $claimed['recovery_attempt_count']);
		self::assertSame('2026-07-21 00:02:00', $claimed['next_recovery_at']);
		self::assertTrue($this->repository->deferHostedRecovery(
			$operation['operation_uuid'],
			1,
			'2026-07-21 00:02:00',
			'2026-07-21 00:05:00',
			'provider_timeout',
			'Provider lookup timed out.'
		));

		$deferred = $this->repository->findByUuid($operation['operation_uuid']);
		self::assertSame(1, (int) $deferred['recovery_attempt_count']);
		self::assertSame('2026-07-21 00:05:00', $deferred['next_recovery_at']);
		self::assertSame('provider_timeout', $deferred['remote_error_code']);

		self::assertTrue($this->repository->claimHostedRecovery(
			$operation['operation_uuid'],
			'2026-07-21 00:05:00',
			'2026-07-20 23:55:00',
			'2026-07-21 00:07:00',
			7
		));
		self::assertFalse($this->repository->deferHostedRecovery(
			$operation['operation_uuid'],
			2,
			'2026-07-21 00:02:00',
			'2026-07-21 00:10:00',
			'stale_worker',
			'A stale worker must not overwrite the newer lease.'
		));
		self::assertSame('2026-07-21 00:07:00', $this->repository->findByUuid($operation['operation_uuid'])['next_recovery_at']);
	}

	public function testPersistedHostedSuccessResetsProviderBudgetAndBypassesAgeGate(): void
	{
		$operation = $this->operation(85, 'purchase:hosted-success');
		$operation['provider_correlation_id'] = $operation['operation_uuid'];
		self::assertIsArray($this->repository->create($operation));
		self::assertTrue($this->repository->claimRemoteProcessing($operation['operation_uuid']));
		self::assertTrue($this->repository->claimHostedRecovery(
			$operation['operation_uuid'],
			'2026-07-21 00:00:00',
			'2026-07-20 23:55:00',
			'2026-07-21 00:02:00',
			7
		));
		self::assertTrue($this->repository->transitionRemote(
			$operation['operation_uuid'],
			'processing',
			'succeeded',
			['vendor_transaction_id' => '51178085']
		));

		$row = $this->repository->findByUuid($operation['operation_uuid']);
		self::assertSame(0, (int) $row['recovery_attempt_count']);
		self::assertNull($row['next_recovery_at']);
		$due = $this->repository->findHostedPurchasesNeedingRecovery(
			'2026-07-20 23:55:00',
			'2026-07-21 00:00:00',
			'2026-07-20 23:55:00',
			7,
			10
		);
		self::assertSame([$operation['operation_uuid']], array_column($due, 'operation_uuid'));
	}

	public function testFreshLocalApplyingHostedSuccessIsNotReclaimedUntilLeaseIsStale(): void
	{
		$operation = $this->operation(86, 'purchase:hosted-local-applying');
		$operation['provider_correlation_id'] = $operation['operation_uuid'];
		self::assertIsArray($this->repository->create($operation));
		self::assertTrue($this->repository->claimRemoteProcessing($operation['operation_uuid']));
		self::assertTrue($this->repository->transitionRemote(
			$operation['operation_uuid'],
			'processing',
			'succeeded',
			['vendor_transaction_id' => '51178086']
		));
		self::assertTrue($this->repository->claimLocalApplying($operation['operation_uuid'], 'pending'));

		$fresh = $this->repository->findHostedPurchasesNeedingRecovery(
			'2026-07-20 23:55:00',
			'2026-07-21 00:00:00',
			'2026-07-20 23:59:59',
			7,
			10
		);
		self::assertSame([], $fresh);

		$stale = $this->repository->findHostedPurchasesNeedingRecovery(
			'2026-07-20 23:55:00',
			'2026-07-21 00:00:00',
			'2026-07-21 00:00:00',
			7,
			10
		);
		self::assertSame([$operation['operation_uuid']], array_column($stale, 'operation_uuid'));
		self::assertTrue($this->repository->claimHostedRecovery(
			$operation['operation_uuid'],
			'2026-07-21 00:00:00',
			'2026-07-21 00:00:00',
			'2026-07-21 00:02:00',
			7
		));
		self::assertSame('2026-07-21 00:00:00', $this->database->lastUpdateWhere['local_claimed_at'] ?? null);
	}

	public function testHostedRecoveryScanIsFairBoundedAndStopsAtAttemptBudget(): void
	{
		$uuids = [];
		for ($sequence = 100; $sequence < 125; ++$sequence) {
			$operation = $this->operation($sequence, 'purchase:hosted-' . $sequence);
			$operation['provider_correlation_id'] = $operation['operation_uuid'];
			self::assertIsArray($this->repository->create($operation));
			self::assertTrue($this->repository->claimRemoteProcessing($operation['operation_uuid']));
			$this->database->update(
				'wp_ys_helcim_operations',
				['created_at' => '2026-07-20 22:00:00'],
				['operation_uuid' => $operation['operation_uuid']]
			);
			$uuids[] = $operation['operation_uuid'];
		}

		$first = $this->repository->findHostedPurchasesNeedingRecovery(
			'2026-07-20 23:55:00',
			'2026-07-21 00:00:00',
			'2026-07-20 23:55:00',
			7,
			20
		);
		self::assertCount(20, $first);
		foreach ($first as $row) {
			self::assertTrue($this->repository->claimHostedRecovery(
				$row['operation_uuid'],
				'2026-07-21 00:00:00',
				'2026-07-20 23:55:00',
				'2026-07-21 00:02:00',
				7
			));
		}

		$second = $this->repository->findHostedPurchasesNeedingRecovery(
			'2026-07-20 23:55:00',
			'2026-07-21 00:00:00',
			'2026-07-20 23:55:00',
			7,
			20
		);
		self::assertSame(array_slice($uuids, 20), array_column($second, 'operation_uuid'));

		$stoppedUuid = $uuids[20];
		$this->database->update(
			'wp_ys_helcim_operations',
			['recovery_attempt_count' => 7, 'next_recovery_at' => null],
			['operation_uuid' => $stoppedUuid]
		);
		$third = $this->repository->findHostedPurchasesNeedingRecovery(
			'2026-07-20 23:55:00',
			'2026-07-21 00:00:00',
			'2026-07-20 23:55:00',
			7,
			20
		);
		self::assertNotContains($stoppedUuid, array_column($third, 'operation_uuid'));
	}

	public function testHostedAttentionQueryIncludesUnresolvedAndLocalBindingFailures(): void
	{
		$indeterminate = $this->operation(126, 'purchase:attention-indeterminate');
		$indeterminate['provider_correlation_id'] = $indeterminate['operation_uuid'];
		self::assertIsArray($this->repository->create($indeterminate));
		self::assertTrue($this->repository->claimRemoteProcessing($indeterminate['operation_uuid']));
		self::assertTrue($this->repository->transitionRemote($indeterminate['operation_uuid'], 'processing', 'indeterminate'));

		$succeeded = $this->operation(127, 'purchase:attention-local');
		$succeeded['provider_correlation_id'] = $succeeded['operation_uuid'];
		self::assertIsArray($this->repository->create($succeeded));
		self::assertTrue($this->repository->claimRemoteProcessing($succeeded['operation_uuid']));
		self::assertTrue($this->repository->transitionRemote(
			$succeeded['operation_uuid'],
			'processing',
			'succeeded',
			['vendor_transaction_id' => '51178127']
		));

		$rows = $this->repository->findHostedPurchasesNeedingAttention(10, 7);
		self::assertIsArray($rows);
		self::assertSame(
			[$indeterminate['operation_uuid'], $succeeded['operation_uuid']],
			array_column($rows, 'operation_uuid')
		);
	}

	public function testPausedHostedRecoveryCanBeClaimedForOneManualAttemptAtATime(): void
	{
		$operation = $this->operation(128, 'purchase:manual-attention');
		$operation['provider_correlation_id'] = $operation['operation_uuid'];
		self::assertIsArray($this->repository->create($operation));
		self::assertTrue($this->repository->claimRemoteProcessing($operation['operation_uuid']));
		$this->database->update(
			'wp_ys_helcim_operations',
			['recovery_attempt_count' => 7, 'next_recovery_at' => null],
			['operation_uuid' => $operation['operation_uuid']]
		);

		self::assertTrue($this->repository->claimPausedHostedRecovery(
			$operation['operation_uuid'],
			'2026-07-21 00:00:00',
			'2026-07-20 23:55:00',
			'2026-07-21 00:02:00',
			7
		));
		self::assertFalse($this->repository->claimPausedHostedRecovery(
			$operation['operation_uuid'],
			'2026-07-21 00:00:00',
			'2026-07-20 23:55:00',
			'2026-07-21 00:02:00',
			7
		));
		self::assertTrue($this->repository->deferHostedRecovery(
			$operation['operation_uuid'],
			7,
			'2026-07-21 00:02:00',
			null,
			'ys_helcim_hosted_recovery_attention_required',
			'Exact provider proof is still unavailable.'
		));
	}

	public function testPausedInlineRecoveryCanBeClaimedForOneManualAttemptAtATimeAndIsGatewayBound(): void
	{
		$operation = $this->operation(136, 'purchase:inline-manual-attention');
		$operation['gateway'] = 'ys_helcim_js';
		$operation['provider_correlation_id'] = $operation['operation_uuid'];
		self::assertIsArray($this->repository->create($operation));
		self::assertTrue($this->repository->claimRemoteProcessing($operation['operation_uuid']));
		$this->database->update(
			'wp_ys_helcim_operations',
			['recovery_attempt_count' => 7, 'next_recovery_at' => null],
			['operation_uuid' => $operation['operation_uuid']]
		);

		self::assertFalse($this->repository->claimPausedPurchaseRecovery(
			$operation['operation_uuid'],
			'ys_helcim',
			'2026-07-21 00:00:00',
			'2026-07-20 23:55:00',
			'2026-07-21 00:02:00',
			7
		));
		self::assertTrue($this->repository->claimPausedPurchaseRecovery(
			$operation['operation_uuid'],
			'ys_helcim_js',
			'2026-07-21 00:00:00',
			'2026-07-20 23:55:00',
			'2026-07-21 00:02:00',
			7
		));
		self::assertFalse($this->repository->claimPausedPurchaseRecovery(
			$operation['operation_uuid'],
			'ys_helcim_js',
			'2026-07-21 00:00:00',
			'2026-07-20 23:55:00',
			'2026-07-21 00:02:00',
			7
		));
	}

	public function testUnscheduledAttentionRecoveryCanBeClaimedOnceBeforeAutomaticAttemptsRun(): void
	{
		$operation = $this->operation(137, 'purchase:inline-unscheduled-attention');
		$operation['gateway'] = 'ys_helcim_js';
		$operation['provider_correlation_id'] = $operation['operation_uuid'];
		self::assertIsArray($this->repository->create($operation));
		self::assertTrue($this->repository->claimRemoteProcessing($operation['operation_uuid']));
		self::assertTrue($this->repository->transitionRemote(
			$operation['operation_uuid'],
			'processing',
			'indeterminate'
		));
		$row = $this->repository->findByUuid($operation['operation_uuid']);
		self::assertSame(0, (int) $row['recovery_attempt_count']);
		self::assertNull($row['next_recovery_at']);

		self::assertFalse($this->repository->claimAttentionPurchaseRecovery(
			$operation['operation_uuid'],
			'ys_helcim',
			'2026-07-21 00:00:00',
			'2026-07-20 23:55:00',
			'2026-07-21 00:02:00',
			7
		));
		self::assertTrue($this->repository->claimAttentionPurchaseRecovery(
			$operation['operation_uuid'],
			'ys_helcim_js',
			'2026-07-21 00:00:00',
			'2026-07-20 23:55:00',
			'2026-07-21 00:02:00',
			7
		));
		self::assertFalse($this->repository->claimAttentionPurchaseRecovery(
			$operation['operation_uuid'],
			'ys_helcim_js',
			'2026-07-21 00:00:00',
			'2026-07-20 23:55:00',
			'2026-07-21 00:02:00',
			7
		));
		self::assertTrue($this->repository->deferPurchaseRecovery(
			$operation['operation_uuid'],
			'ys_helcim_js',
			0,
			'2026-07-21 00:02:00',
			null,
			'ys_helcim_purchase_recovery_attention_required',
			'Exact provider proof is still unavailable.'
		));
		$row = $this->repository->findByUuid($operation['operation_uuid']);
		self::assertSame(0, (int) $row['recovery_attempt_count']);
		self::assertNull($row['next_recovery_at']);
		self::assertNotNull($row['active_scope_key']);
	}

	public function testAttentionRecoveryCannotStealAnUnexpiredLease(): void
	{
		$operation = $this->operation(138, 'purchase:inline-active-manual-lease');
		$operation['gateway'] = 'ys_helcim_js';
		$operation['provider_correlation_id'] = $operation['operation_uuid'];
		self::assertIsArray($this->repository->create($operation));
		self::assertTrue($this->repository->claimRemoteProcessing($operation['operation_uuid']));
		self::assertTrue($this->repository->transitionRemote(
			$operation['operation_uuid'],
			'processing',
			'indeterminate'
		));
		$this->database->update(
			'wp_ys_helcim_operations',
			['next_recovery_at' => '2026-07-21 00:03:00'],
			['operation_uuid' => $operation['operation_uuid']]
		);

		self::assertFalse($this->repository->claimAttentionPurchaseRecovery(
			$operation['operation_uuid'],
			'ys_helcim_js',
			'2026-07-21 00:00:00',
			'2026-07-20 23:55:00',
			'2026-07-21 00:02:00',
			7
		));
		self::assertSame(
			'2026-07-21 00:03:00',
			$this->repository->findByUuid($operation['operation_uuid'])['next_recovery_at']
		);
	}

	public function testExactPositiveTerminalStateWinsRaceAgainstEmptyObservation(): void
	{
		$operation = $this->operation(129, 'purchase:positive-race');
		$operation['provider_correlation_id'] = $operation['operation_uuid'];
		self::assertIsArray($this->repository->create($operation));
		self::assertTrue($this->repository->claimRemoteProcessing($operation['operation_uuid']));
		self::assertTrue($this->repository->transitionRemote(
			$operation['operation_uuid'],
			'processing',
			'succeeded',
			['vendor_transaction_id' => '51178129']
		));

		self::assertFalse($this->repository->recordHostedEmptyObservation(
			$operation['operation_uuid'],
			'processing'
		));
		$row = $this->repository->findByUuid($operation['operation_uuid']);
		self::assertSame('succeeded', $row['remote_status']);
		self::assertSame('51178129', $row['vendor_transaction_id']);
		self::assertNull($row['remote_error_code']);
	}

    /** @return array<string, mixed> */
    private function operation(int $sequence, string $scope, string $operationType = 'purchase'): array
    {
        $operationUuid = sprintf('00000000-0000-4000-8000-%012d', $sequence);
        $transactionUuid = 'fc-transaction-123';

        $operation = [
            'operation_uuid' => $operationUuid,
            'idempotency_key' => YSHelcimIdempotency::generate($operationType, $transactionUuid, 2100, 'test', $operationUuid),
            'scope_key' => $scope,
            'operation_type' => $operationType,
            'gateway' => 'ys_helcim',
            'order_id' => 10,
            'transaction_id' => 20,
            'transaction_uuid' => $transactionUuid,
            'amount' => 2100,
            'currency' => 'USD',
            'payment_mode' => 'test',
            'provider_correlation_id' => 'corr-' . $sequence,
            'request_fingerprint' => hash('sha256', 'request-' . $sequence),
        ];

        if (in_array(strtolower($operationType), ['refund', 'reverse'], true)) {
            $payload = YSHelcimRefundPayload::normalize([]);
            $operation['local_payload'] = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $operation['local_payload_hash'] = YSHelcimRefundPayload::hash($payload);
            $operation['source_vendor_transaction_id'] = '51177061';
        }

        return $operation;
    }
}
