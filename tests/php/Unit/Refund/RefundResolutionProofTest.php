<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Refund;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundResolutionProof;

final class RefundResolutionProofTest extends TestCase
{
    // 這張訂單 purchase 的 invoiceNumber（＝作業 UUID）；另一張訂單用 OTHER_INVOICE。
    private const INVOICE = '3b0c6f2e-8d41-4a7e-9c55-1f2a3b4c5d6e';
    private const OTHER_INVOICE = '7d9e1a20-5c3b-4f86-a1d4-6e7f8a9b0c1d';

    public function testItBuildsCanonicalProofForTheHelcimShapedRecordWithExplicitAttestation(): void
    {
        // Helcim 的 card-transactions 讀回沒有 originalTransactionId：正式站一律走 attestation，
        // 綁定只靠 invoiceNumber 與同 invoice 家族。
        $proof = YSHelcimRefundResolutionProof::verify(
            $this->operation(),
            $this->candidate(),
            $this->source(),
            $this->family()
        );

        self::assertIsArray($proof);
        self::assertSame('81177094', $proof['candidate_transaction_id']);
        self::assertSame('81177061', $proof['source_transaction_id']);
        self::assertSame('resolve_positive', $proof['action']);
        self::assertTrue($proof['parent_attestation_required']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $proof['proof_digest']);
        self::assertSame(2100, $proof['candidate']['amount_cents']);
        self::assertSame(5000, $proof['source']['amount_cents']);
        self::assertSame(self::INVOICE, $proof['invoice_number']);
        self::assertSame(self::INVOICE, $proof['candidate']['invoice_number']);
        self::assertSame(self::INVOICE, $proof['source']['invoice_number']);
    }

    public function testAMatchingProviderParentFieldRemovesTheAttestationRequirement(): void
    {
        $proof = YSHelcimRefundResolutionProof::verify(
            $this->operation(),
            $this->candidate(['originalTransactionId' => 81177061]),
            $this->source(),
            $this->family()
        );

        self::assertIsArray($proof);
        self::assertFalse($proof['parent_attestation_required']);
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
        $family = [
            $this->source(),
            $candidate,
        ];

        $proof = YSHelcimRefundResolutionProof::verify($operation, $candidate, $this->source(), $family);

        self::assertIsArray($proof);
        self::assertSame(5000, $proof['source']['amount_cents']);
    }

    public function testInvoiceNumbersCompareWithoutCaseButMustBeTheSameInvoice(): void
    {
        $upper = strtoupper(self::INVOICE);
        $proof = YSHelcimRefundResolutionProof::verify(
            $this->operation(),
            $this->candidate(['invoiceNumber' => $upper]),
            $this->source(),
            [$this->source(), $this->candidate(['invoiceNumber' => $upper])]
        );

        self::assertIsArray($proof);
        self::assertSame($upper, $proof['candidate']['invoice_number']);
    }

    /**
     * 安全審查 F1：A 單（$21）與 B 單（$21）的退款同時結果不明，或 B 單的退款是在 Helcim 後台做的。
     * 管理員在 A 單的作業輸入 B 單的退款編號：類型、APPROVED、金額、幣別全部相符，以前只要勾 attestation 就會通過。
     */
    public function testARefundOfAnotherOrdersInvoiceIsNeverAcceptedForThisOperation(): void
    {
        $otherOrdersRefund = $this->candidate(['invoiceNumber' => self::OTHER_INVOICE]);

        $result = YSHelcimRefundResolutionProof::verify(
            $this->operation(),
            $otherOrdersRefund,
            $this->source(),
            [$this->source(), $otherOrdersRefund]
        );

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_resolution_proof_mismatch', $result->get_error_code());
    }

    public function testTheExactCandidateReadDecidesTheInvoiceEvenWhenTheFamilyListDisagrees(): void
    {
        // 清單篩選是否精確無法在本機證明：清單把候選列在這張單的 invoice 底下，
        // 但單筆 GET 讀回的是別張單的 invoice，仍以單筆讀回為準並拒絕。
        $result = YSHelcimRefundResolutionProof::verify(
            $this->operation(),
            $this->candidate(['invoiceNumber' => self::OTHER_INVOICE]),
            $this->source(),
            $this->family()
        );

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_resolution_proof_mismatch', $result->get_error_code());
    }

    #[DataProvider('missingInvoiceProvider')]
    public function testItRejectsEvidenceWithoutAUsableInvoiceNumberOnEitherSide(
        array $candidateChanges,
        array $sourceChanges
    ): void {
        $candidate = $this->candidate($candidateChanges);
        $source = $this->source($sourceChanges);

        $result = YSHelcimRefundResolutionProof::verify($this->operation(), $candidate, $source, [$source, $candidate]);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_resolution_proof_mismatch', $result->get_error_code());
    }

    public static function missingInvoiceProvider(): iterable
    {
        yield 'candidate invoice missing' => [['invoiceNumber' => null], []];
        yield 'candidate invoice empty' => [['invoiceNumber' => ''], []];
        yield 'candidate invoice blank' => [['invoiceNumber' => '   '], []];
        yield 'candidate invoice not a string' => [['invoiceNumber' => 1001], ['invoiceNumber' => '1001']];
        yield 'invoice has a control character' => [['invoiceNumber' => "INV\t1001"], ['invoiceNumber' => "INV\t1001"]];
        yield 'source invoice missing' => [[], ['invoiceNumber' => null]];
        yield 'both invoices missing' => [['invoiceNumber' => null], ['invoiceNumber' => null]];
        yield 'source invoice too long' => [['invoiceNumber' => str_repeat('a', 129)], ['invoiceNumber' => str_repeat('a', 129)]];
    }

    #[DataProvider('invalidEvidenceProvider')]
    public function testItRejectsIncompleteOrContradictoryProviderEvidence(
        array $operationChanges,
        array $candidateChanges,
        array $sourceChanges
    ): void {
        $candidate = $this->candidate($candidateChanges);
        $source = $this->source($sourceChanges);
        $result = YSHelcimRefundResolutionProof::verify(
            $this->operation($operationChanges),
            $candidate,
            $source,
            [$source, $candidate]
        );

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_resolution_proof_mismatch', $result->get_error_code());
    }

    public static function invalidEvidenceProvider(): iterable
    {
        yield 'candidate id does not equal requested candidate' => [
            ['resolution_candidate_id' => '81177095'], [], [],
        ];
        yield 'candidate equals source' => [
            ['resolution_candidate_id' => '81177061'], ['transactionId' => 81177061], [],
        ];
        yield 'candidate not approved' => [[], ['status' => 'DECLINED'], []];
        yield 'candidate wrong type' => [[], ['type' => 'purchase'], []];
        yield 'candidate wrong exact cents' => [[], ['amount' => '21.01'], []];
        yield 'candidate malformed cents' => [[], ['amount' => '21.001'], []];
        yield 'candidate wrong currency' => [[], ['currency' => 'CAD'], []];
        yield 'present parent field mismatches source' => [[], ['originalTransactionId' => 81177060], []];
        yield 'present parent field is empty' => [[], ['originalTransactionId' => null], []];
        yield 'two parent fields contradict' => [[], ['originalTransactionId' => 81177061, 'parentTransactionId' => 81177060], []];
        yield 'source id mismatch' => [[], [], ['transactionId' => 81177060]];
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

    /**
     * 安全審查 F2：同 invoice 家族規則與外部退款同步相同；列得到的家族不完整或有衝突就拒絕。
     */
    #[DataProvider('familyConflictProvider')]
    public function testItAppliesTheSameInvoiceFamilyRulesAsTheProviderRefundSync(string $scenario): void
    {
        [$operation, $candidate, $family] = $this->familyScenario($scenario);

        $result = YSHelcimRefundResolutionProof::verify($operation, $candidate, $this->source(), $family);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_resolution_proof_mismatch', $result->get_error_code());
    }

    public static function familyConflictProvider(): iterable
    {
        yield 'refund voided later in Helcim (refund_was_voided)' => ['refund_was_voided'];
        yield 'reverse while the family lists an approved refund (reverse_of_refund)' => ['reverse_of_refund'];
        yield 'reverse while the family lists a refund of any status' => ['reverse_of_unapproved_refund'];
        yield 'family list is empty' => ['empty'];
        yield 'candidate missing from the family list' => ['candidate_missing'];
        yield 'source missing from the family list' => ['source_missing'];
        yield 'candidate listed under another invoice only' => ['candidate_other_invoice'];
        yield 'family lists the candidate as no longer approved' => ['candidate_not_approved_in_list'];
        yield 'family lists the candidate with another type' => ['candidate_other_type_in_list'];
        yield 'family lists the source as not approved' => ['source_not_approved_in_list'];
        yield 'family is not a list' => ['not_a_list'];
    }

    #[DataProvider('familyAllowedProvider')]
    public function testUnrelatedFamilyRecordsDoNotBlockAnExactCandidate(string $scenario): void
    {
        [$operation, $candidate, $family] = $this->familyScenario($scenario);

        $result = YSHelcimRefundResolutionProof::verify($operation, $candidate, $this->source(), $family);

        self::assertIsArray($result);
    }

    public static function familyAllowedProvider(): iterable
    {
        yield 'an earlier refund and its void' => ['earlier_void'];
        yield 'a later void of a different amount' => ['later_void_other_amount'];
        yield 'a later declined void of the same amount' => ['later_declined_void'];
        yield 'a conflicting void on another invoice' => ['conflict_on_other_invoice'];
        yield 'a declined earlier purchase attempt on the same invoice' => ['declined_attempt'];
        yield 'reverse with only the purchase and itself' => ['reverse_clean'];
    }

    public function testProofDigestChangesWhenAnyBoundProviderEvidenceChanges(): void
    {
        $first = YSHelcimRefundResolutionProof::verify($this->operation(), $this->candidate(), $this->source(), $this->family());
        $second = YSHelcimRefundResolutionProof::verify(
            $this->operation(),
            $this->candidate(),
            $this->source(['amount' => '50.01']),
            [$this->source(['amount' => '50.01']), $this->candidate()]
        );
        $otherInvoice = YSHelcimRefundResolutionProof::verify(
            $this->operation(),
            $this->candidate(['invoiceNumber' => self::OTHER_INVOICE]),
            $this->source(['invoiceNumber' => self::OTHER_INVOICE]),
            [
                $this->source(['invoiceNumber' => self::OTHER_INVOICE]),
                $this->candidate(['invoiceNumber' => self::OTHER_INVOICE]),
            ]
        );

        self::assertIsArray($first);
        self::assertIsArray($second);
        self::assertIsArray($otherInvoice);
        self::assertNotSame($first['proof_digest'], $second['proof_digest']);
        self::assertNotSame($first['proof_digest'], $otherInvoice['proof_digest']);
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>,2:array<mixed>} */
    private function familyScenario(string $scenario): array
    {
        $operation = $this->operation();
        $candidate = $this->candidate();
        $source = $this->source();
        $reverseOperation = $this->operation(['operation_type' => 'reverse', 'amount' => 5000]);
        $reverseCandidate = $this->candidate(['type' => 'reverse', 'amount' => '50.00']);

        return match ($scenario) {
            'refund_was_voided' => [$operation, $candidate, [
                $source,
                $candidate,
                $this->record(81177120, 'reverse', 'APPROVED', '21.00'),
            ]],
            'reverse_of_refund' => [$reverseOperation, $reverseCandidate, [
                $source,
                $this->record(81177080, 'refund', 'APPROVED', '50.00'),
                $reverseCandidate,
            ]],
            'reverse_of_unapproved_refund' => [$reverseOperation, $reverseCandidate, [
                $source,
                $this->record(81177080, 'refund', 'REVERSED', '50.00'),
                $reverseCandidate,
            ]],
            'empty' => [$operation, $candidate, []],
            'candidate_missing' => [$operation, $candidate, [$source]],
            'source_missing' => [$operation, $candidate, [$candidate]],
            'candidate_other_invoice' => [$operation, $candidate, [
                $source,
                $this->candidate(['invoiceNumber' => self::OTHER_INVOICE]),
            ]],
            'candidate_not_approved_in_list' => [$operation, $candidate, [
                $source,
                $this->candidate(['status' => 'REVERSED']),
            ]],
            'candidate_other_type_in_list' => [$operation, $candidate, [
                $source,
                $this->candidate(['type' => 'reverse']),
            ]],
            'source_not_approved_in_list' => [$operation, $candidate, [
                $this->source(['status' => 'DECLINED']),
                $candidate,
            ]],
            'not_a_list' => [$operation, $candidate, ['purchase' => $source, 'refund' => $candidate]],
            'earlier_void' => [$operation, $candidate, [
                $source,
                $this->record(81177070, 'refund', 'APPROVED', '21.00'),
                $this->record(81177080, 'reverse', 'APPROVED', '21.00'),
                $candidate,
            ]],
            'later_void_other_amount' => [$operation, $candidate, [
                $source,
                $candidate,
                $this->record(81177120, 'reverse', 'APPROVED', '10.00'),
            ]],
            'later_declined_void' => [$operation, $candidate, [
                $source,
                $candidate,
                $this->record(81177120, 'reverse', 'DECLINED', '21.00'),
            ]],
            'conflict_on_other_invoice' => [$operation, $candidate, [
                $source,
                $candidate,
                $this->record(81177120, 'reverse', 'APPROVED', '21.00', self::OTHER_INVOICE),
            ]],
            'declined_attempt' => [$operation, $candidate, [
                $this->record(81177050, 'purchase', 'DECLINED', '50.00'),
                $source,
                $candidate,
            ]],
            'reverse_clean' => [$reverseOperation, $reverseCandidate, [$source, $reverseCandidate]],
            default => throw new \LogicException('Unknown family scenario ' . $scenario),
        };
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
            'source_vendor_transaction_id' => '81177061',
            'resolution_candidate_id' => '81177094',
        ], $changes);
    }

    /**
     * Helcim GET card-transactions/{id} 實際的形狀：沒有 originalTransactionId，退款繼承 purchase 的 invoiceNumber。
     *
     * @param array<string,mixed> $changes
     */
    private function candidate(array $changes = []): array
    {
        return array_merge([
            'transactionId' => 81177094,
            'status' => 'APPROVED',
            'type' => 'refund',
            'amount' => '21.00',
            'currency' => 'USD',
            'invoiceNumber' => self::INVOICE,
        ], $changes);
    }

    /** @param array<string,mixed> $changes */
    private function source(array $changes = []): array
    {
        return array_merge([
            'transactionId' => 81177061,
            'status' => 'APPROVED',
            'type' => 'purchase',
            'amount' => '50.00',
            'currency' => 'USD',
            'invoiceNumber' => self::INVOICE,
        ], $changes);
    }

    /** @return list<array<string,mixed>> */
    private function family(): array
    {
        return [$this->source(), $this->candidate()];
    }

    /** @return array<string,mixed> */
    private function record(int $id, string $type, string $status, string $amount, string $invoice = self::INVOICE): array
    {
        return [
            'transactionId' => $id,
            'status' => $status,
            'type' => $type,
            'amount' => $amount,
            'currency' => 'USD',
            'invoiceNumber' => $invoice,
        ];
    }
}
