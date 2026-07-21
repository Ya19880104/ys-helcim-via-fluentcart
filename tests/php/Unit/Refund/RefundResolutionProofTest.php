<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Refund;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundResolutionProof;

final class RefundResolutionProofTest extends TestCase
{
    public function testItBuildsCanonicalProofForAnExactlyBoundRefund(): void
    {
        $proof = YSHelcimRefundResolutionProof::verify(
            $this->operation(),
            $this->candidate(),
            $this->source()
        );

        self::assertIsArray($proof);
        self::assertSame('51177094', $proof['candidate_transaction_id']);
        self::assertSame('51177061', $proof['source_transaction_id']);
        self::assertSame('resolve_positive', $proof['action']);
        self::assertFalse($proof['parent_attestation_required']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $proof['proof_digest']);
        self::assertSame(2100, $proof['candidate']['amount_cents']);
        self::assertSame(5000, $proof['source']['amount_cents']);
    }

    public function testMissingProviderParentFieldRequiresExplicitOperatorAttestation(): void
    {
        $candidate = $this->candidate();
        unset($candidate['originalTransactionId']);

        $proof = YSHelcimRefundResolutionProof::verify($this->operation(), $candidate, $this->source());

        self::assertIsArray($proof);
        self::assertTrue($proof['parent_attestation_required']);
    }

    public function testReverseRequiresTheExactSourceAmount(): void
    {
        $operation = $this->operation([
            'operation_type' => 'reverse',
            'amount' => 5000,
        ]);
        $candidate = $this->candidate([
            'type' => 'reverse',
            'amount' => '50.00',
        ]);

        $proof = YSHelcimRefundResolutionProof::verify($operation, $candidate, $this->source());

        self::assertIsArray($proof);
        self::assertSame(5000, $proof['source']['amount_cents']);
    }

    #[DataProvider('invalidEvidenceProvider')]
    public function testItRejectsIncompleteOrContradictoryProviderEvidence(
        array $operationChanges,
        array $candidateChanges,
        array $sourceChanges
    ): void {
        $result = YSHelcimRefundResolutionProof::verify(
            $this->operation($operationChanges),
            $this->candidate($candidateChanges),
            $this->source($sourceChanges)
        );

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_resolution_proof_mismatch', $result->get_error_code());
    }

    public static function invalidEvidenceProvider(): iterable
    {
        yield 'candidate id does not equal requested candidate' => [
            ['resolution_candidate_id' => '51177095'], [], [],
        ];
        yield 'candidate equals source' => [
            ['resolution_candidate_id' => '51177061'], ['transactionId' => 51177061], [],
        ];
        yield 'candidate not approved' => [[], ['status' => 'DECLINED'], []];
        yield 'candidate wrong type' => [[], ['type' => 'purchase'], []];
        yield 'candidate wrong exact cents' => [[], ['amount' => '21.01'], []];
        yield 'candidate malformed cents' => [[], ['amount' => '21.001'], []];
        yield 'candidate wrong currency' => [[], ['currency' => 'CAD'], []];
        yield 'present parent field mismatches source' => [[], ['originalTransactionId' => 51177060], []];
        yield 'present parent field is empty' => [[], ['originalTransactionId' => null], []];
        yield 'two parent fields contradict' => [[], ['parentTransactionId' => 51177060], []];
        yield 'source id mismatch' => [[], [], ['transactionId' => 51177060]];
        yield 'source not approved' => [[], [], ['status' => 'DECLINED']];
        yield 'source wrong type' => [[], [], ['type' => 'refund']];
        yield 'source malformed cents' => [[], [], ['amount' => '50.001']];
        yield 'source less than partial refund' => [[], [], ['amount' => '20.99']];
        yield 'source wrong currency' => [[], [], ['currency' => 'CAD']];
        yield 'reverse source amount is not exact' => [
            ['operation_type' => 'reverse'],
            ['type' => 'reverse'],
            ['amount' => '50.01'],
        ];
    }

    public function testProofDigestChangesWhenAnyBoundProviderEvidenceChanges(): void
    {
        $first = YSHelcimRefundResolutionProof::verify($this->operation(), $this->candidate(), $this->source());
        $second = YSHelcimRefundResolutionProof::verify(
            $this->operation(),
            $this->candidate(),
            $this->source(['amount' => '50.01'])
        );

        self::assertIsArray($first);
        self::assertIsArray($second);
        self::assertNotSame($first['proof_digest'], $second['proof_digest']);
    }

    /** @param array<string,mixed> $changes */
    private function operation(array $changes = []): array
    {
        return array_merge([
            'operation_uuid' => '11111111-2222-4333-8444-555555555555',
            'operation_type' => 'refund',
            'gateway' => 'ys_helcim',
            'payment_mode' => 'test',
            'order_id' => 10,
            'amount' => 2100,
            'currency' => 'USD',
            'source_vendor_transaction_id' => '51177061',
            'resolution_candidate_id' => '51177094',
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
}
