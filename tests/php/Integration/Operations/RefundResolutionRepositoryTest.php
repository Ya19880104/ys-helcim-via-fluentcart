<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Integration\Operations;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationScope;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundResolutionRepository;
use YangSheep\Helcim\FluentCart\Tests\Doubles\RefundResolutionWpdb;

final class RefundResolutionRepositoryTest extends TestCase
{
    private const OPERATION_UUID = '11111111-2222-4333-8444-555555555555';
    private const OTHER_UUID = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
    private const CHALLENGE_HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const PHRASE_HASH = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const PROOF_DIGEST = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    private RefundResolutionWpdb $db;
    private YSHelcimRefundResolutionRepository $repository;

    protected function setUp(): void
    {
        $this->db = new RefundResolutionWpdb();
        $this->db->operations[self::OPERATION_UUID] = $this->operation();
        $this->repository = new YSHelcimRefundResolutionRepository(
            $this->db,
            static fn (): string => '2026-07-21 01:01:00'
        );
    }

    public function testMalformedOperationUuidIsRejectedBeforeAnyDatabaseRead(): void
    {
        self::assertNull($this->repository->findOperation('------------------------------------'));
        self::assertSame([], $this->db->log);
    }

    public function testPromoteStaleProcessingDelegatesToTheOperationJournalWithTheSameDatabase(): void
    {
        self::assertTrue(method_exists($this->repository, 'promoteStaleProcessing'));

        $result = $this->repository->promoteStaleProcessing(self::OPERATION_UUID);

        self::assertSame(1, $result);
        self::assertTrue((bool) array_filter(
            $this->db->log,
            static fn (string $entry): bool => str_contains($entry, 'YS_HELCIM_PROMOTE_STALE_REFUND_PROCESSING')
        ));
    }

    public function testCreateChallengePersistsNoRawChallengeAndRejectsAnAlreadyReservedCandidate(): void
    {
        $created = $this->repository->createChallenge($this->challenge());

        self::assertTrue($created);
        $stored = $this->db->challenges[self::CHALLENGE_HASH];
        self::assertArrayNotHasKey('challenge', $stored);
        self::assertSame(self::CHALLENGE_HASH, $stored['challenge_hash']);
        self::assertNull($stored['used_at']);

        $this->db->audits[self::OTHER_UUID] = $this->resolution([
            'operation_uuid' => self::OTHER_UUID,
            'challenge_hash' => str_repeat('d', 64),
        ]);
        $duplicate = $this->repository->createChallenge($this->challenge([
            'challenge_hash' => str_repeat('e', 64),
        ]));
        self::assertInstanceOf(\WP_Error::class, $duplicate);
        self::assertSame('ys_helcim_resolution_candidate_used', $duplicate->get_error_code());
    }

    public function testCommitAtomicallyAuditsReservesCandidateAndCasSucceedsWithoutUnlockingScope(): void
    {
        self::assertTrue($this->repository->createChallenge($this->challenge()));

        $result = $this->repository->commitResolution($this->resolution());

        self::assertIsArray($result);
        self::assertFalse($result['replayed']);
        self::assertSame('succeeded', $this->db->operations[self::OPERATION_UUID]['remote_status']);
        self::assertSame('pending', $this->db->operations[self::OPERATION_UUID]['local_status']);
        self::assertSame('51177094', $this->db->operations[self::OPERATION_UUID]['vendor_transaction_id']);
        self::assertSame(self::scope(10), $this->db->operations[self::OPERATION_UUID]['active_scope_key']);
        self::assertNull($this->db->operations[self::OPERATION_UUID]['remote_error_code']);
        self::assertNull($this->db->operations[self::OPERATION_UUID]['encrypted_material']);
        self::assertSame('2026-07-21 01:01:00', $this->db->challenges[self::CHALLENGE_HASH]['used_at']);
        self::assertSame(7, $this->db->audits[self::OPERATION_UUID]['actor_user_id']);
        self::assertFalse($this->db->audits[self::OPERATION_UUID]['parent_attested']);

        $start = array_search('START TRANSACTION', $this->db->log, true);
        $journalReceipt = array_search('SELECT operation receipt FOR UPDATE', $this->db->log, true);
        $fluentCartReceipt = array_search('SELECT FluentCart receipt FOR UPDATE', $this->db->log, true);
        $audit = array_search('INSERT wp_ys_helcim_refund_resolutions', $this->db->log, true);
        $operationUpdate = array_search('UPDATE wp_ys_helcim_operations', $this->db->log, true);
        $challengeUpdate = array_search('UPDATE wp_ys_helcim_resolution_challenges', $this->db->log, true);
        $commit = array_search('COMMIT', $this->db->log, true);
        self::assertIsInt($start);
        self::assertIsInt($journalReceipt);
        self::assertIsInt($fluentCartReceipt);
        self::assertIsInt($audit);
        self::assertIsInt($operationUpdate);
        self::assertIsInt($challengeUpdate);
        self::assertIsInt($commit);
        self::assertTrue(
            $start < $journalReceipt
            && $journalReceipt < $fluentCartReceipt
            && $fluentCartReceipt < $audit
            && $audit < $operationUpdate
            && $operationUpdate < $challengeUpdate
            && $challengeUpdate < $commit
        );
    }

    public function testLocalFailedStateCanBeResolvedWhileScopeRemainsLocked(): void
    {
        $this->db->operations[self::OPERATION_UUID]['local_status'] = 'failed';
        self::assertTrue($this->repository->createChallenge($this->challenge()));

        $result = $this->repository->commitResolution($this->resolution(['local_status' => 'failed']));

        self::assertIsArray($result);
        self::assertSame('failed', $this->db->operations[self::OPERATION_UUID]['local_status']);
        self::assertSame(self::scope(10), $this->db->operations[self::OPERATION_UUID]['active_scope_key']);
    }

    public function testCasFailureRollsBackAuditReservationAndChallengeConsumption(): void
    {
        self::assertTrue($this->repository->createChallenge($this->challenge()));
        $this->db->failOperationCas = true;

        $result = $this->repository->commitResolution($this->resolution());

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_resolution_conflict', $result->get_error_code());
        self::assertSame('indeterminate', $this->db->operations[self::OPERATION_UUID]['remote_status']);
        self::assertNull($this->db->challenges[self::CHALLENGE_HASH]['used_at']);
        self::assertSame([], $this->db->audits);
        self::assertContains('ROLLBACK', $this->db->log);
    }

    public function testCommitExpiryUsesTheRepositoryClockNotCallerSuppliedTime(): void
    {
        $this->repository = new YSHelcimRefundResolutionRepository(
            $this->db,
            static fn (): string => '2026-07-21 01:01:00'
        );
        $this->db->challenges[self::CHALLENGE_HASH] = $this->challenge([
            'expires_at' => '2026-07-21 01:00:59',
            'used_at' => null,
        ]);

        $result = $this->repository->commitResolution($this->resolution([
            'now' => '2026-07-21 00:59:00',
        ]));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_resolution_conflict', $result->get_error_code());
        self::assertSame('indeterminate', $this->db->operations[self::OPERATION_UUID]['remote_status']);
        self::assertSame([], $this->db->audits);
    }

    public function testCandidateReservationIsUniqueAcrossOperations(): void
    {
        self::assertTrue($this->repository->createChallenge($this->challenge()));
        self::assertIsArray($this->repository->commitResolution($this->resolution()));

        $this->db->operations[self::OTHER_UUID] = $this->operation([
            'operation_uuid' => self::OTHER_UUID,
            'order_id' => 11,
            'scope_key' => self::scope(11),
            'active_scope_key' => self::scope(11),
        ]);
        $otherHash = str_repeat('d', 64);
        $this->db->challenges[$otherHash] = $this->challenge([
            'challenge_hash' => $otherHash,
            'operation_uuid' => self::OTHER_UUID,
            'state_updated_at' => '2026-07-21 01:00:00',
            'used_at' => null,
        ]);

        $duplicate = $this->repository->commitResolution($this->resolution([
            'challenge_hash' => $otherHash,
            'operation_uuid' => self::OTHER_UUID,
            'active_scope_key' => self::scope(11),
        ]));

        self::assertInstanceOf(\WP_Error::class, $duplicate);
        self::assertSame('ys_helcim_resolution_candidate_used', $duplicate->get_error_code());
        self::assertSame('indeterminate', $this->db->operations[self::OTHER_UUID]['remote_status']);
        self::assertCount(1, $this->db->audits);
    }

    public function testAuditUniqueConstraintIsTheFinalSiteWideRaceDefenseAfterChallengeCreation(): void
    {
        self::assertTrue($this->repository->createChallenge($this->challenge()));
        $this->db->audits[self::OTHER_UUID] = $this->resolution([
            'operation_uuid' => self::OTHER_UUID,
            'challenge_hash' => str_repeat('d', 64),
            'payment_mode' => 'live',
        ]);

        $result = $this->repository->commitResolution($this->resolution());

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_resolution_candidate_used', $result->get_error_code());
        self::assertSame('indeterminate', $this->db->operations[self::OPERATION_UUID]['remote_status']);
        self::assertNull($this->db->challenges[self::CHALLENGE_HASH]['used_at']);
        self::assertCount(1, $this->db->audits);
        self::assertContains('ROLLBACK', $this->db->log);
    }

    public function testAuditCandidateValidationErrorIsNotMisreportedAsConcurrentReuse(): void
    {
        self::assertTrue($this->repository->createChallenge($this->challenge()));
        $this->db->failAuditInsertWithNonDuplicateCandidateError = true;

        $result = $this->repository->commitResolution($this->resolution());

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_resolution_store_unavailable', $result->get_error_code());
        self::assertSame([], $this->db->audits);
        self::assertContains('ROLLBACK', $this->db->log);
    }

    public function testCommitRejectsCandidateAlreadyBoundToAnotherOperationJournalRow(): void
    {
        self::assertTrue($this->repository->createChallenge($this->challenge()));
        $this->db->operations[self::OTHER_UUID] = $this->operation([
            'operation_uuid' => self::OTHER_UUID,
            'gateway' => 'ys_helcim',
            'order_id' => 11,
            'scope_key' => self::scope(11),
            'active_scope_key' => null,
            'remote_status' => 'succeeded',
            'local_status' => 'applied',
            'vendor_transaction_id' => '51177094',
        ]);

        $result = $this->repository->commitResolution($this->resolution());

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_resolution_candidate_used', $result->get_error_code());
        self::assertSame('indeterminate', $this->db->operations[self::OPERATION_UUID]['remote_status']);
        self::assertSame([], $this->db->audits);
        self::assertContains('ROLLBACK', $this->db->log);
    }

    public function testCommitRejectsCandidateAlreadyRecordedAsAFluentCartHelcimRefundReceipt(): void
    {
        self::assertTrue($this->repository->createChallenge($this->challenge()));
        $this->db->transactions[31] = [
            'id' => 31,
            'order_id' => 99,
            'uuid' => self::OTHER_UUID,
            'transaction_type' => 'refund',
            'vendor_charge_id' => '51177094',
            'payment_method' => 'ys_helcim',
            'payment_mode' => 'test',
        ];

        $result = $this->repository->commitResolution($this->resolution());

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_resolution_candidate_used', $result->get_error_code());
        self::assertSame('indeterminate', $this->db->operations[self::OPERATION_UUID]['remote_status']);
        self::assertSame([], $this->db->audits);
    }

    #[DataProvider('receiptLookupFailureProvider')]
    public function testCommitFailsClosedWhenAReceiptOwnershipLookupFails(string $failureFlag): void
    {
        self::assertTrue($this->repository->createChallenge($this->challenge()));
        $this->db->{$failureFlag} = true;

        $result = $this->repository->commitResolution($this->resolution());

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_resolution_store_unavailable', $result->get_error_code());
        self::assertSame('indeterminate', $this->db->operations[self::OPERATION_UUID]['remote_status']);
        self::assertSame([], $this->db->audits);
        self::assertContains('ROLLBACK', $this->db->log);
    }

    public static function receiptLookupFailureProvider(): iterable
    {
        yield 'operation journal lookup' => ['failOperationReceiptRead'];
        yield 'FluentCart receipt lookup' => ['failTransactionReceiptRead'];
    }

    #[DataProvider('receiptLookupExceptionProvider')]
    public function testCommitRollsBackAndFailsClosedWhenAReceiptOwnershipLookupThrows(string $exceptionFlag): void
    {
        self::assertTrue($this->repository->createChallenge($this->challenge()));
        $this->db->{$exceptionFlag} = true;

        $result = $this->repository->commitResolution($this->resolution());

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_resolution_store_unavailable', $result->get_error_code());
        self::assertSame('indeterminate', $this->db->operations[self::OPERATION_UUID]['remote_status']);
        self::assertSame([], $this->db->audits);
        self::assertContains('ROLLBACK', $this->db->log);
    }

    public static function receiptLookupExceptionProvider(): iterable
    {
        yield 'operation journal exception' => ['throwOperationReceiptRead'];
        yield 'FluentCart receipt exception' => ['throwTransactionReceiptRead'];
    }

    public function testDatabaseUniqueReceiptRaceIsReportedAsCandidateUsedAndRolledBack(): void
    {
        self::assertTrue($this->repository->createChallenge($this->challenge()));
        $this->db->failOperationCasWithDuplicateReceipt = true;

        $result = $this->repository->commitResolution($this->resolution());

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_resolution_candidate_used', $result->get_error_code());
        self::assertSame('indeterminate', $this->db->operations[self::OPERATION_UUID]['remote_status']);
        self::assertSame([], $this->db->audits);
        self::assertNull($this->db->challenges[self::CHALLENGE_HASH]['used_at']);
    }

    public function testAnUnrelatedUniqueConstraintFailureIsNotMisreportedAsCandidateReuse(): void
    {
        self::assertTrue($this->repository->createChallenge($this->challenge()));
        $this->db->failOperationCasWithOtherDuplicate = true;

        $result = $this->repository->commitResolution($this->resolution());

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_resolution_store_unavailable', $result->get_error_code());
        self::assertSame([], $this->db->audits);
        self::assertContains('ROLLBACK', $this->db->log);
    }

    public function testTransactionStartExceptionFailsClosedWithoutLeakingAnException(): void
    {
        self::assertTrue($this->repository->createChallenge($this->challenge()));
        $this->db->throwStart = true;

        $result = $this->repository->commitResolution($this->resolution());

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_resolution_store_unavailable', $result->get_error_code());
        self::assertSame('indeterminate', $this->db->operations[self::OPERATION_UUID]['remote_status']);
    }

    #[DataProvider('transactionBodyExceptionProvider')]
    public function testTransactionBodyExceptionsAlwaysRollBackAndFailClosed(string $exceptionFlag): void
    {
        self::assertTrue($this->repository->createChallenge($this->challenge()));
        $this->db->{$exceptionFlag} = true;

        $result = $this->repository->commitResolution($this->resolution());

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_resolution_store_unavailable', $result->get_error_code());
        self::assertSame('indeterminate', $this->db->operations[self::OPERATION_UUID]['remote_status']);
        self::assertSame([], $this->db->audits);
        self::assertNull($this->db->challenges[self::CHALLENGE_HASH]['used_at']);
        self::assertContains('ROLLBACK', $this->db->log);
    }

    public static function transactionBodyExceptionProvider(): iterable
    {
        yield 'challenge read throws' => ['throwChallengeRead'];
        yield 'operation read throws' => ['throwOperationRead'];
        yield 'operation update throws' => ['throwOperationUpdate'];
        yield 'commit throws' => ['throwCommit'];
    }

    public function testChallengeConservativelyRejectsCandidateReuseAcrossPaymentModesSiteWide(): void
    {
        $this->db->audits[self::OTHER_UUID] = $this->resolution([
            'operation_uuid' => self::OTHER_UUID,
            'challenge_hash' => str_repeat('d', 64),
            'payment_mode' => 'live',
        ]);

        $result = $this->repository->createChallenge($this->challenge());

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_resolution_candidate_used', $result->get_error_code());
    }

    public function testCommitConservativelyRejectsCrossModeJournalReceiptReuseSiteWide(): void
    {
        self::assertTrue($this->repository->createChallenge($this->challenge()));
        $this->db->operations[self::OTHER_UUID] = $this->operation([
            'operation_uuid' => self::OTHER_UUID,
            'gateway' => 'ys_helcim',
            'payment_mode' => 'live',
            'order_id' => 11,
            'scope_key' => self::scope(11),
            'active_scope_key' => null,
            'remote_status' => 'succeeded',
            'local_status' => 'applied',
            'vendor_transaction_id' => '51177094',
        ]);

        $result = $this->repository->commitResolution($this->resolution());

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_resolution_candidate_used', $result->get_error_code());
    }

    public function testCommitConservativelyRejectsCrossModeFluentCartReceiptReuseSiteWide(): void
    {
        self::assertTrue($this->repository->createChallenge($this->challenge()));
        $this->db->transactions[31] = [
            'id' => 31,
            'order_id' => 99,
            'uuid' => self::OTHER_UUID,
            'transaction_type' => 'refund',
            'vendor_charge_id' => '51177094',
            'payment_method' => 'ys_helcim',
            'payment_mode' => 'live',
        ];

        $result = $this->repository->commitResolution($this->resolution());

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_resolution_candidate_used', $result->get_error_code());
    }

    public function testSafeReplayDoesNotFailBecauseItsOwnReceiptsNowExist(): void
    {
        self::assertTrue($this->repository->createChallenge($this->challenge()));
        self::assertIsArray($this->repository->commitResolution($this->resolution()));
        $this->db->transactions[31] = [
            'id' => 31,
            'order_id' => 10,
            'uuid' => self::OPERATION_UUID,
            'transaction_type' => 'refund',
            'vendor_charge_id' => '51177094',
            'payment_method' => 'ys_helcim_js',
            'payment_mode' => 'test',
        ];

        $replay = $this->repository->commitResolution($this->resolution());

        self::assertIsArray($replay);
        self::assertTrue($replay['replayed']);
    }

    #[DataProvider('changedBindingProvider')]
    public function testCommitRejectsExpiredOrChangedChallengeOperationAndProofBindings(
        array $challengeChanges,
        array $operationChanges,
        array $resolutionChanges
    ): void {
        $this->db->operations[self::OPERATION_UUID] = $this->operation($operationChanges);
        $this->db->challenges[self::CHALLENGE_HASH] = $this->challenge($challengeChanges) + ['used_at' => null];
        $expectedRemoteStatus = $this->db->operations[self::OPERATION_UUID]['remote_status'];

        $result = $this->repository->commitResolution($this->resolution($resolutionChanges));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_resolution_conflict', $result->get_error_code());
        self::assertSame([], $this->db->audits);
        self::assertSame($expectedRemoteStatus, $this->db->operations[self::OPERATION_UUID]['remote_status']);
    }

    public static function changedBindingProvider(): iterable
    {
        yield 'expired' => [['expires_at' => '2026-07-21 01:00:59'], [], []];
        yield 'actor changed' => [['actor_user_id' => 8], [], []];
        yield 'phrase changed' => [['phrase_hash' => str_repeat('d', 64)], [], []];
        yield 'proof changed' => [['proof_digest' => str_repeat('d', 64)], [], []];
        yield 'source changed' => [['source_transaction_id' => '51177060'], [], []];
        yield 'action changed' => [['action' => 'negative_unlock'], [], []];
        yield 'state updated at changed' => [['state_updated_at' => '2026-07-21 00:59:59'], [], []];
        yield 'remote state changed' => [[], ['remote_status' => 'processing'], []];
        yield 'local state changed' => [[], ['local_status' => 'applying'], []];
        yield 'scope unlocked' => [[], ['active_scope_key' => null], []];
        yield 'raw scope rejected' => [[], ['scope_key' => 'refund-order:10', 'active_scope_key' => 'refund-order:10'], []];
        yield 'scope changed' => [[], ['active_scope_key' => self::scope(11)], []];
        yield 'vendor id already set' => [[], ['vendor_transaction_id' => '51177094'], []];
        yield 'requested mode changed' => [[], [], ['payment_mode' => 'live']];
        yield 'requested gateway changed' => [[], [], ['gateway' => 'ys_helcim']];
        yield 'requested local status changed' => [[], [], ['local_status' => 'failed']];
        yield 'attestation requirement changed' => [[], [], ['parent_attestation_required' => true, 'parent_attested' => true]];
    }

    public function testUsedChallengeReturnsIdempotentReplayAndNeverMutatesAgain(): void
    {
        self::assertTrue($this->repository->createChallenge($this->challenge()));
        self::assertIsArray($this->repository->commitResolution($this->resolution()));
        $logCount = count($this->db->log);

        $replay = $this->repository->commitResolution($this->resolution());

        self::assertIsArray($replay);
        self::assertTrue($replay['replayed']);
        self::assertCount(1, $this->db->audits);
        $newLog = array_slice($this->db->log, $logCount);
        self::assertNotContains('INSERT wp_ys_helcim_refund_resolutions', $newLog);
        self::assertNotContains('UPDATE wp_ys_helcim_operations', $newLog);
    }

    public function testReplayLookupIsBoundToConsumedChallengeActorPhraseCandidateAndSucceededOperation(): void
    {
        self::assertTrue($this->repository->createChallenge($this->challenge()));
        self::assertIsArray($this->repository->commitResolution($this->resolution()));

        $binding = [
            'challenge_hash' => self::CHALLENGE_HASH,
            'operation_uuid' => self::OPERATION_UUID,
            'candidate_transaction_id' => '51177094',
            'actor_user_id' => 7,
            'phrase_hash' => self::PHRASE_HASH,
            'parent_attested' => false,
        ];
        $replay = $this->repository->findResolutionReplay($binding);
        self::assertIsArray($replay);
        self::assertTrue($replay['replayed']);

        foreach ([
            ['actor_user_id' => 8],
            ['phrase_hash' => str_repeat('d', 64)],
            ['candidate_transaction_id' => '51177095'],
            ['challenge_hash' => str_repeat('d', 64)],
            ['parent_attested' => true],
        ] as $change) {
            self::assertNull($this->repository->findResolutionReplay(array_merge($binding, $change)));
        }
    }

    public function testReplayRejectsAnyTamperedAuditOperationOrConsumptionEvidence(): void
    {
        self::assertTrue($this->repository->createChallenge($this->challenge()));
        self::assertIsArray($this->repository->commitResolution($this->resolution()));
        $binding = [
            'challenge_hash' => self::CHALLENGE_HASH,
            'operation_uuid' => self::OPERATION_UUID,
            'candidate_transaction_id' => '51177094',
            'actor_user_id' => 7,
            'phrase_hash' => self::PHRASE_HASH,
            'parent_attested' => false,
        ];
        $operation = $this->db->operations[self::OPERATION_UUID];
        $challenge = $this->db->challenges[self::CHALLENGE_HASH];
        $audit = $this->db->audits[self::OPERATION_UUID];

        $mutations = [
            static function (RefundResolutionWpdb $db): void {
                $db->audits[self::OPERATION_UUID]['operation_type'] = 'reverse';
            },
            static function (RefundResolutionWpdb $db): void {
                $db->audits[self::OPERATION_UUID]['resolved_at'] = '2026-07-21 01:01:01';
            },
            static function (RefundResolutionWpdb $db): void {
                $db->challenges[self::CHALLENGE_HASH]['used_at'] = '2026-07-21 01:01:01';
            },
            static function (RefundResolutionWpdb $db): void {
                $db->operations[self::OPERATION_UUID]['gateway'] = 'ys_helcim';
            },
            static function (RefundResolutionWpdb $db): void {
                $db->operations[self::OPERATION_UUID]['payment_mode'] = 'live';
            },
            static function (RefundResolutionWpdb $db): void {
                $db->operations[self::OPERATION_UUID]['operation_type'] = 'reverse';
            },
            static function (RefundResolutionWpdb $db): void {
                $db->operations[self::OPERATION_UUID]['source_vendor_transaction_id'] = '51177060';
            },
            static function (RefundResolutionWpdb $db): void {
                $db->operations[self::OPERATION_UUID]['scope_key'] = 'other-scope';
                $db->operations[self::OPERATION_UUID]['active_scope_key'] = 'other-scope';
            },
            static function (RefundResolutionWpdb $db): void {
                $db->operations[self::OPERATION_UUID]['local_status'] = 'unknown';
            },
        ];

        foreach ($mutations as $mutate) {
            $this->db->operations[self::OPERATION_UUID] = $operation;
            $this->db->challenges[self::CHALLENGE_HASH] = $challenge;
            $this->db->audits[self::OPERATION_UUID] = $audit;
            $mutate($this->db);

            self::assertNull($this->repository->findResolutionReplay($binding));
        }
    }

    public function testTransactionStartAndCommitFailuresFailClosedAndRollback(): void
    {
        self::assertTrue($this->repository->createChallenge($this->challenge()));
        $this->db->failStart = true;
        $startFailure = $this->repository->commitResolution($this->resolution());
        self::assertInstanceOf(\WP_Error::class, $startFailure);
        self::assertSame('ys_helcim_resolution_store_unavailable', $startFailure->get_error_code());

        $this->db->failStart = false;
        $this->db->failCommit = true;
        $commitFailure = $this->repository->commitResolution($this->resolution());
        self::assertInstanceOf(\WP_Error::class, $commitFailure);
        self::assertSame('ys_helcim_resolution_store_unavailable', $commitFailure->get_error_code());
        self::assertSame('indeterminate', $this->db->operations[self::OPERATION_UUID]['remote_status']);
        self::assertSame([], $this->db->audits);
        self::assertNull($this->db->challenges[self::CHALLENGE_HASH]['used_at']);
    }

    /** @param array<string,mixed> $changes */
    private function operation(array $changes = []): array
    {
        return array_merge([
            'operation_uuid' => self::OPERATION_UUID,
            'operation_type' => 'refund',
            'gateway' => 'ys_helcim_js',
            'payment_mode' => 'test',
            'order_id' => 10,
            'transaction_id' => 20,
            'amount' => 2100,
            'currency' => 'USD',
            'source_vendor_transaction_id' => '51177061',
            'vendor_transaction_id' => null,
            'remote_status' => 'indeterminate',
            'local_status' => 'pending',
            'scope_key' => self::scope(10),
            'active_scope_key' => self::scope(10),
            'remote_error_code' => 'provider_timeout',
            'remote_error_message' => 'Unknown.',
            'encrypted_material' => 'encrypted',
            'material_expires_at' => '2026-07-21 02:00:00',
            'updated_at' => '2026-07-21 01:00:00',
        ], $changes);
    }

    /** @param array<string,mixed> $changes */
    private function challenge(array $changes = []): array
    {
        return array_merge([
            'challenge_hash' => self::CHALLENGE_HASH,
            'operation_uuid' => self::OPERATION_UUID,
            'gateway' => 'ys_helcim_js',
            'payment_mode' => 'test',
            'candidate_transaction_id' => '51177094',
            'source_transaction_id' => '51177061',
            'action' => 'resolve_positive',
            'proof_digest' => self::PROOF_DIGEST,
            'state_updated_at' => '2026-07-21 01:00:00',
            'actor_user_id' => 7,
            'phrase_hash' => self::PHRASE_HASH,
            'parent_attestation_required' => false,
            'created_at' => '2026-07-21 01:00:00',
            'expires_at' => '2026-07-21 01:05:00',
        ], $changes);
    }

    /** @param array<string,mixed> $changes */
    private function resolution(array $changes = []): array
    {
        return array_merge([
            'challenge_hash' => self::CHALLENGE_HASH,
            'operation_uuid' => self::OPERATION_UUID,
            'gateway' => 'ys_helcim_js',
            'payment_mode' => 'test',
            'operation_type' => 'refund',
            'local_status' => 'pending',
            'active_scope_key' => self::scope(10),
            'candidate_transaction_id' => '51177094',
            'source_transaction_id' => '51177061',
            'action' => 'resolve_positive',
            'proof_digest' => self::PROOF_DIGEST,
            'state_updated_at' => '2026-07-21 01:00:00',
            'actor_user_id' => 7,
            'phrase_hash' => self::PHRASE_HASH,
            'parent_attestation_required' => false,
            'parent_attested' => false,
            'now' => '2026-07-21 01:01:00',
        ], $changes);
    }

    private static function scope(int $orderId): string
    {
        return YSHelcimOperationScope::fromBusinessKey('refund-order:' . $orderId);
    }
}
