<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Refund;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationScope;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundResolutionService;
use YangSheep\Helcim\FluentCart\Tests\Doubles\RefundResolutionStoreDouble;

final class RefundResolutionServiceTest extends TestCase
{
    private const OPERATION_UUID = '11111111-2222-4333-8444-555555555555';
    private const CANDIDATE_ID = '51177094';
    private const SOURCE_ID = '51177061';
    private const NOW = 1784595600; // 2026-07-21 01:00:00 UTC.

    private RefundResolutionStoreDouble $store;
    /** @var array<int,array{gateway:string,mode:string}> */
    private array $credentialCalls;
    /** @var array<int,array{id:string,credential:string,gateway:string,mode:string}> */
    private array $providerCalls;
    /** @var string[] */
    private array $localCalls;
    /** @var array<string,array<string,mixed>|\WP_Error> */
    private array $providerRows;
    private mixed $localResult;

    protected function setUp(): void
    {
        $this->store = new RefundResolutionStoreDouble();
        $this->store->operations[self::OPERATION_UUID] = $this->operation();
        $this->credentialCalls = [];
        $this->providerCalls = [];
        $this->localCalls = [];
        $this->localResult = ['local_status' => 'applied'];
        $this->providerRows = [
            self::CANDIDATE_ID => $this->candidate(),
            self::SOURCE_ID => $this->source(),
        ];
    }

    public function testInspectCreatesAHashOnlyFiveMinuteChallengeBoundToStoredIdentityAndProof(): void
    {
        $result = $this->service()->inspect(self::OPERATION_UUID, self::CANDIDATE_ID, 7);

        self::assertIsArray($result);
        self::assertSame('confirmation_required', $result['status']);
        self::assertSame(str_repeat('a5', 32), $result['challenge']);
        self::assertSame('2026-07-21 01:05:00', $result['challenge_expires_at']);
        self::assertSame(
            'RESOLVE 11111111-2222-4333-8444-555555555555 WITH HELCIM 51177094',
            $result['confirmation_phrase']
        );
        self::assertFalse($result['parent_attestation_required']);
        self::assertSame([['gateway' => 'ys_helcim_js', 'mode' => 'test']], $this->credentialCalls);
        self::assertSame([self::CANDIDATE_ID, self::SOURCE_ID], array_column($this->providerCalls, 'id'));

        $stored = array_values($this->store->challenges)[0];
        self::assertArrayNotHasKey('challenge', $stored);
        self::assertSame(hash('sha256', str_repeat('a5', 32)), $stored['challenge_hash']);
        self::assertSame(self::OPERATION_UUID, $stored['operation_uuid']);
        self::assertSame(self::CANDIDATE_ID, $stored['candidate_transaction_id']);
        self::assertSame(self::SOURCE_ID, $stored['source_transaction_id']);
        self::assertSame('resolve_positive', $stored['action']);
        self::assertSame('2026-07-21 01:00:00', $stored['state_updated_at']);
        self::assertSame(7, $stored['actor_user_id']);
        self::assertSame('2026-07-21 01:05:00', $stored['expires_at']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $stored['proof_digest']);
    }

    public function testInspectPromotesAStaleProcessingClaimBeforeLoadingProviderProof(): void
    {
        $this->store->operations[self::OPERATION_UUID]['remote_status'] = 'processing';
        $this->store->promoteResult = 1;

        $result = $this->service()->inspect(self::OPERATION_UUID, self::CANDIDATE_ID, 7);

        self::assertIsArray($result);
        self::assertSame('confirmation_required', $result['status']);
        self::assertSame([self::OPERATION_UUID], $this->store->promoteCalls);
        self::assertSame('indeterminate', $this->store->operations[self::OPERATION_UUID]['remote_status']);
    }

    public function testInspectFailsClosedWhenStaleProcessingPromotionIsUnavailable(): void
    {
        $this->store->operations[self::OPERATION_UUID]['remote_status'] = 'processing';
        $this->store->promoteResult = new \WP_Error('ys_helcim_journal_unavailable', 'Unavailable.');

        $result = $this->service()->inspect(self::OPERATION_UUID, self::CANDIDATE_ID, 7);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_journal_unavailable', $result->get_error_code());
        self::assertSame([], $this->providerCalls);
        self::assertSame([], $this->store->challenges);
    }

    public function testInspectMarksMissingParentBindingAndUsesAnAttestationPhrase(): void
    {
        unset($this->providerRows[self::CANDIDATE_ID]['originalTransactionId']);

        $result = $this->service()->inspect(self::OPERATION_UUID, self::CANDIDATE_ID, 7);

        self::assertIsArray($result);
        self::assertTrue($result['parent_attestation_required']);
        self::assertSame(
            'ATTEST AND RESOLVE 11111111-2222-4333-8444-555555555555 WITH HELCIM 51177094',
            $result['confirmation_phrase']
        );
    }

    #[DataProvider('invalidOperationProvider')]
    public function testInspectRejectsAnyOperationThatIsNotTheExactActiveIndeterminateRefundScope(array $changes): void
    {
        $this->store->operations[self::OPERATION_UUID] = $this->operation($changes);

        $result = $this->service()->inspect(self::OPERATION_UUID, self::CANDIDATE_ID, 7);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_resolution_operation_conflict', $result->get_error_code());
        self::assertSame([], $this->credentialCalls);
        self::assertSame([], $this->providerCalls);
        self::assertSame([], $this->store->challenges);
    }

    public static function invalidOperationProvider(): iterable
    {
        yield 'remote processing' => [['remote_status' => 'processing']];
        yield 'remote failed' => [['remote_status' => 'failed']];
        yield 'local applying' => [['local_status' => 'applying']];
        yield 'local recorded' => [['local_status' => 'recorded']];
        yield 'scope missing' => [['active_scope_key' => null]];
        yield 'raw business scope was not canonicalized' => [['scope_key' => 'refund-order:10', 'active_scope_key' => 'refund-order:10']];
        yield 'scope belongs to another order' => [[
            'scope_key' => YSHelcimOperationScope::fromBusinessKey('refund-order:11'),
            'active_scope_key' => YSHelcimOperationScope::fromBusinessKey('refund-order:11'),
        ]];
        yield 'active scope differs' => [['active_scope_key' => YSHelcimOperationScope::fromBusinessKey('refund-order:11')]];
        yield 'operation type purchase' => [['operation_type' => 'purchase']];
        yield 'unknown gateway' => [['gateway' => 'other']];
        yield 'unknown mode' => [['payment_mode' => 'sandbox']];
        yield 'malformed updated at' => [['updated_at' => 'tomorrow']];
        yield 'missing source id' => [['source_vendor_transaction_id' => null]];
        yield 'non-positive amount' => [['amount' => 0]];
        yield 'unsupported currency' => [['currency' => 'EUR']];
    }

    public function testInspectUsesOnlyTheCredentialResolvedFromTheStoredGatewayAndMode(): void
    {
        $this->store->operations[self::OPERATION_UUID] = $this->operation([
            'gateway' => 'ys_helcim',
            'payment_mode' => 'live',
        ]);

        $result = $this->service()->inspect(self::OPERATION_UUID, self::CANDIDATE_ID, 9);

        self::assertIsArray($result);
        self::assertSame([['gateway' => 'ys_helcim', 'mode' => 'live']], $this->credentialCalls);
        self::assertSame(['stored-token', 'stored-token'], array_column($this->providerCalls, 'credential'));
        self::assertSame(['live', 'live'], array_column($this->providerCalls, 'mode'));
    }

    public function testInspectFailsClosedWhenCredentialOrEitherProviderReadIsUnavailable(): void
    {
        $credentialFailure = $this->service(
            static fn (): \WP_Error => new \WP_Error('missing_credential', 'Missing.')
        )->inspect(self::OPERATION_UUID, self::CANDIDATE_ID, 7);
        self::assertInstanceOf(\WP_Error::class, $credentialFailure);
        self::assertSame([], $this->store->challenges);

        $this->providerRows[self::SOURCE_ID] = new \WP_Error('transport', 'Unavailable.');
        $providerFailure = $this->service()->inspect(self::OPERATION_UUID, self::CANDIDATE_ID, 7);
        self::assertInstanceOf(\WP_Error::class, $providerFailure);
        self::assertSame('ys_helcim_resolution_provider_unavailable', $providerFailure->get_error_code());
        self::assertSame([], $this->store->challenges);
    }

    public function testInspectRejectsMalformedInputAndInsufficientChallengeEntropy(): void
    {
        $badCandidate = $this->service()->inspect(self::OPERATION_UUID, '051177094', 7);
        self::assertInstanceOf(\WP_Error::class, $badCandidate);

        $badActor = $this->service()->inspect(self::OPERATION_UUID, self::CANDIDATE_ID, 0);
        self::assertInstanceOf(\WP_Error::class, $badActor);

        $badRandom = $this->service(null, static fn (): string => str_repeat('x', 15))
            ->inspect(self::OPERATION_UUID, self::CANDIDATE_ID, 7);
        self::assertInstanceOf(\WP_Error::class, $badRandom);
        self::assertSame('ys_helcim_resolution_challenge_unavailable', $badRandom->get_error_code());
    }

    public function testCommitReFetchesAndReHashesBeforeAtomicResolutionThenRecordsLocally(): void
    {
        $inspect = $this->service()->inspect(self::OPERATION_UUID, self::CANDIDATE_ID, 7);
        self::assertIsArray($inspect);

        $result = $this->service()->commit([
            'operation_uuid' => self::OPERATION_UUID,
            'candidate_transaction_id' => self::CANDIDATE_ID,
            'challenge' => $inspect['challenge'],
            'confirmation_phrase' => $inspect['confirmation_phrase'],
            'parent_attestation' => false,
        ], 7);

        self::assertIsArray($result);
        self::assertSame('resolved', $result['status']);
        self::assertFalse($result['replayed']);
        self::assertSame('succeeded', $result['remote_status']);
        self::assertSame(['local_status' => 'applied'], $result['local']);
        self::assertSame([self::CANDIDATE_ID, self::SOURCE_ID, self::CANDIDATE_ID, self::SOURCE_ID], array_column($this->providerCalls, 'id'));
        self::assertSame([self::OPERATION_UUID], $this->localCalls);
        self::assertCount(1, $this->store->commitCalls);
        self::assertSame($inspect['proof_digest'], $this->store->commitCalls[0]['proof_digest']);
        self::assertSame(self::scope(10), $this->store->operations[self::OPERATION_UUID]['active_scope_key']);
        self::assertSame([self::OPERATION_UUID, self::OPERATION_UUID], $this->store->promoteCalls);
    }

    public function testCommitRequiresAttestationExactlyWhenProviderHasNoParentField(): void
    {
        unset($this->providerRows[self::CANDIDATE_ID]['originalTransactionId']);
        $inspect = $this->service()->inspect(self::OPERATION_UUID, self::CANDIDATE_ID, 7);
        self::assertIsArray($inspect);

        $missing = $this->service()->commit($this->commitInput($inspect, false), 7);
        self::assertInstanceOf(\WP_Error::class, $missing);
        self::assertSame('ys_helcim_resolution_attestation_required', $missing->get_error_code());

        $resolved = $this->service()->commit($this->commitInput($inspect, true), 7);
        self::assertIsArray($resolved);
        self::assertTrue($this->store->commitCalls[0]['parent_attestation_required']);
        self::assertTrue($this->store->commitCalls[0]['parent_attested']);
    }

    public function testCommitRejectsUnnecessaryAttestationAndAnyPhraseDifference(): void
    {
        $inspect = $this->service()->inspect(self::OPERATION_UUID, self::CANDIDATE_ID, 7);
        self::assertIsArray($inspect);

        $unnecessary = $this->service()->commit($this->commitInput($inspect, true), 7);
        self::assertInstanceOf(\WP_Error::class, $unnecessary);
        self::assertSame('ys_helcim_resolution_attestation_mismatch', $unnecessary->get_error_code());

        $wrongPhrase = $this->commitInput($inspect, false);
        $wrongPhrase['confirmation_phrase'] = strtolower($wrongPhrase['confirmation_phrase']);
        $wrong = $this->service()->commit($wrongPhrase, 7);
        self::assertInstanceOf(\WP_Error::class, $wrong);
        self::assertSame('ys_helcim_resolution_confirmation_mismatch', $wrong->get_error_code());
        self::assertSame([], $this->store->commitCalls);
    }

    public function testCommitRejectsExpiredChangedStateChangedProofAndWrongActorBindings(): void
    {
        $inspect = $this->service()->inspect(self::OPERATION_UUID, self::CANDIDATE_ID, 7);
        self::assertIsArray($inspect);

        $this->store->operations[self::OPERATION_UUID]['updated_at'] = '2026-07-21 01:00:01';
        $stateChanged = $this->service()->commit($this->commitInput($inspect, false), 7);
        self::assertInstanceOf(\WP_Error::class, $stateChanged);
        self::assertSame('ys_helcim_resolution_conflict', $stateChanged->get_error_code());

        $this->setUp();
        $inspect = $this->service()->inspect(self::OPERATION_UUID, self::CANDIDATE_ID, 7);
        $this->providerRows[self::SOURCE_ID]['amount'] = '50.01';
        $proofChanged = $this->service()->commit($this->commitInput($inspect, false), 7);
        self::assertInstanceOf(\WP_Error::class, $proofChanged);
        self::assertSame('ys_helcim_resolution_conflict', $proofChanged->get_error_code());

        $this->setUp();
        $inspect = $this->service()->inspect(self::OPERATION_UUID, self::CANDIDATE_ID, 7);
        $wrongActor = $this->service()->commit($this->commitInput($inspect, false), 8);
        self::assertInstanceOf(\WP_Error::class, $wrongActor);
        self::assertSame('ys_helcim_resolution_conflict', $wrongActor->get_error_code());

        $this->setUp();
        $inspect = $this->service()->inspect(self::OPERATION_UUID, self::CANDIDATE_ID, 7);
        $expired = $this->service(null, null, self::NOW + 301)->commit($this->commitInput($inspect, false), 7);
        self::assertInstanceOf(\WP_Error::class, $expired);
        self::assertSame('ys_helcim_resolution_conflict', $expired->get_error_code());
    }

    public function testCommitRejectsMalformedOrDifferentChallengeWithoutAProviderRead(): void
    {
        $inspect = $this->service()->inspect(self::OPERATION_UUID, self::CANDIDATE_ID, 7);
        $callsAfterInspect = count($this->providerCalls);

        foreach (['short', str_repeat('a', 65), str_repeat('A', 64)] as $malformedChallenge) {
            $input = $this->commitInput($inspect, false);
            $input['challenge'] = $malformedChallenge;
            $result = $this->service()->commit($input, 7);

            self::assertInstanceOf(\WP_Error::class, $result);
            self::assertSame('ys_helcim_invalid_resolution_request', $result->get_error_code());
        }
        self::assertCount($callsAfterInspect, $this->providerCalls);
        self::assertSame([], $this->store->commitCalls);
    }

    public function testCommittedReplayResumesLocalRecordingWithoutAnyProviderReadOrSecondCommit(): void
    {
        $localAttempts = 0;
        $this->localResult = static function () use (&$localAttempts): array|\WP_Error {
            ++$localAttempts;
            return $localAttempts === 1
                ? new \WP_Error('local_failed', 'Retry later.')
                : ['local_status' => 'applied'];
        };

        $inspect = $this->service()->inspect(self::OPERATION_UUID, self::CANDIDATE_ID, 7);
        $input = $this->commitInput($inspect, false);
        $first = $this->service()->commit($input, 7);
        self::assertIsArray($first);
        self::assertSame('attention_required', $first['local_recording_status']);
        $providerReadsAfterFirst = count($this->providerCalls);

        $replay = $this->service()->commit($input, 7);

        self::assertIsArray($replay);
        self::assertTrue($replay['replayed']);
        self::assertSame(['local_status' => 'applied'], $replay['local']);
        self::assertSame($providerReadsAfterFirst, count($this->providerCalls));
        self::assertCount(1, $this->store->commitCalls);
        self::assertSame(2, $localAttempts);
    }

    public function testReplayCannotBeUsedWithAnotherActorPhraseCandidateOrToken(): void
    {
        $inspect = $this->service()->inspect(self::OPERATION_UUID, self::CANDIDATE_ID, 7);
        $input = $this->commitInput($inspect, false);
        self::assertIsArray($this->service()->commit($input, 7));
        $reads = count($this->providerCalls);

        $wrongActor = $this->service()->commit($input, 8);
        self::assertInstanceOf(\WP_Error::class, $wrongActor);

        $wrongPhrase = $input;
        $wrongPhrase['confirmation_phrase'] .= ' ';
        self::assertInstanceOf(\WP_Error::class, $this->service()->commit($wrongPhrase, 7));

        $wrongToken = $input;
        $wrongToken['challenge'] = str_repeat('00', 32);
        self::assertInstanceOf(\WP_Error::class, $this->service()->commit($wrongToken, 7));

        self::assertSame($reads, count($this->providerCalls));
        self::assertCount(1, $this->store->commitCalls);
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
            'source_vendor_transaction_id' => self::SOURCE_ID,
            'remote_status' => 'indeterminate',
            'local_status' => 'pending',
            'scope_key' => self::scope(10),
            'active_scope_key' => self::scope(10),
            'updated_at' => '2026-07-21 01:00:00',
            'vendor_transaction_id' => null,
        ], $changes);
    }

    /** @param array<string,mixed> $changes */
    private function candidate(array $changes = []): array
    {
        return array_merge([
            'transactionId' => 51177094,
            'status' => 'APPROVED',
            'type' => 'refund',
            'amount' => '21.00',
            'currency' => 'USD',
            'originalTransactionId' => 51177061,
        ], $changes);
    }

    /** @param array<string,mixed> $changes */
    private function source(array $changes = []): array
    {
        return array_merge([
            'transactionId' => 51177061,
            'status' => 'APPROVED',
            'type' => 'purchase',
            'amount' => '50.00',
            'currency' => 'USD',
        ], $changes);
    }

    private function service(
        ?callable $credentialResolver = null,
        ?callable $randomFactory = null,
        int $now = self::NOW
    ): YSHelcimRefundResolutionService {
        $credentialResolver ??= function (string $gateway, string $mode): string {
            $this->credentialCalls[] = compact('gateway', 'mode');
            return 'stored-token';
        };
        $providerReader = function (string $id, string $credential, string $gateway, string $mode): array|\WP_Error {
            $this->providerCalls[] = compact('id', 'credential', 'gateway', 'mode');
            return $this->providerRows[$id] ?? new \WP_Error('not_found', 'Not found.');
        };
        $localRecorder = function (string $operationUuid): array|\WP_Error {
            $this->localCalls[] = $operationUuid;
            return is_callable($this->localResult)
                ? ($this->localResult)()
                : $this->localResult;
        };

        return new YSHelcimRefundResolutionService(
            $this->store,
            $credentialResolver,
            $providerReader,
            $localRecorder,
            $randomFactory ?? static fn (): string => str_repeat("\xA5", 32),
            static fn (): int => $now
        );
    }

    /** @param array<string,mixed> $inspect */
    private function commitInput(array $inspect, bool $attestation): array
    {
        return [
            'operation_uuid' => self::OPERATION_UUID,
            'candidate_transaction_id' => self::CANDIDATE_ID,
            'challenge' => $inspect['challenge'],
            'confirmation_phrase' => $inspect['confirmation_phrase'],
            'parent_attestation' => $attestation,
        ];
    }

    private static function scope(int $orderId): string
    {
        return YSHelcimOperationScope::fromBusinessKey('refund-order:' . $orderId);
    }
}
