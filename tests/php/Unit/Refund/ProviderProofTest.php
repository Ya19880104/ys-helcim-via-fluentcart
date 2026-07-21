<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Refund;

use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimProviderProof;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundResult;

final class ProviderProofTest extends TestCase
{
    public function testApprovedRefundRequiresExactProviderProof(): void
    {
        $result = YSHelcimProviderProof::classify(
            [
                'status' => 'APPROVED',
                'type' => 'refund',
                'transactionId' => '51177123',
                'amount' => 21.00,
                'currency' => 'USD',
            ],
            'refund',
            2100,
            'USD'
        );

        self::assertSame(YSHelcimRefundResult::SUCCEEDED, $result->status());
        self::assertSame('51177123', $result->vendorTransactionId());
    }

    /**
     * @dataProvider invalidTransactionIds
     */
    public function testApprovedResponseWithInvalidTransactionIdIsIndeterminate(mixed $transactionId): void
    {
        $result = YSHelcimProviderProof::classify(
            [
                'status' => 'APPROVED',
                'type' => 'refund',
                'transactionId' => $transactionId,
                'amount' => 21.00,
                'currency' => 'USD',
            ],
            'refund',
            2100,
            'USD'
        );

        self::assertSame(YSHelcimRefundResult::INDETERMINATE, $result->status());
    }

    /**
     * @dataProvider mismatchedProofs
     */
    public function testApprovedResponseWithMismatchedProofIsIndeterminate(array $response): void
    {
        $result = YSHelcimProviderProof::classify($response, 'refund', 2100, 'USD');

        self::assertSame(YSHelcimRefundResult::INDETERMINATE, $result->status());
    }

    public function testExplicitDeclineIsDefinite(): void
    {
        $result = YSHelcimProviderProof::classify(
            [
                'status' => 'DECLINED',
                'type' => 'refund',
                'transactionId' => '51177123',
                'amount' => 21.00,
                'currency' => 'USD',
            ],
            'refund',
            2100,
            'USD'
        );

        self::assertSame(YSHelcimRefundResult::DECLINED, $result->status());
    }

    /**
     * @dataProvider ambiguousProviderResponses
     */
    public function testUnknownOrIncompleteProviderStatusIsIndeterminate(array $response): void
    {
        $result = YSHelcimProviderProof::classify($response, 'refund', 2100, 'USD');

        self::assertSame(YSHelcimRefundResult::INDETERMINATE, $result->status());
    }

    /**
     * @dataProvider indeterminateErrors
     */
    public function testTransportServerAndInvalidResponsesAreIndeterminate(\WP_Error $error): void
    {
        $result = YSHelcimProviderProof::classify($error, 'refund', 2100, 'USD');

        self::assertSame(YSHelcimRefundResult::INDETERMINATE, $result->status());
    }

    public function testOnlySanitizedExactOpenBatchProviderErrorRequiresReverse(): void
    {
        $structured = new \WP_Error(
            'ys_helcim_api_error',
            'Card Transaction cannot be refunded',
            [
                'kind' => 'provider',
                'http_code' => 422,
                'indeterminate' => false,
                'provider_errors' => ['cardtransactionid' => 'Card Transaction cannot be refunded'],
            ]
        );
        $messageOnly = new \WP_Error(
            'ys_helcim_api_error',
            'Card Transaction cannot be refunded',
            ['kind' => 'provider', 'http_code' => 422, 'indeterminate' => false]
        );
        $scalarStructured = new \WP_Error(
            'ys_helcim_api_error',
            'Card Transaction cannot be refunded',
            [
                'kind' => 'provider',
                'http_code' => 400,
                'indeterminate' => false,
                'provider_errors' => 'Card Transaction cannot be refunded',
            ]
        );
        $similar = new \WP_Error(
            'ys_helcim_api_error',
            'This card cannot be refunded for another reason',
            [
                'kind' => 'provider',
                'http_code' => 422,
                'indeterminate' => false,
                'provider_errors' => ['cardtransactionid' => 'This card cannot be refunded for another reason'],
            ]
        );

        self::assertSame(
            YSHelcimRefundResult::REQUIRES_REVERSE,
            YSHelcimProviderProof::classify($structured, 'refund', 2100, 'USD')->status()
        );
        self::assertSame(
            YSHelcimRefundResult::REQUIRES_REVERSE,
            YSHelcimProviderProof::classify($scalarStructured, 'refund', 2100, 'USD')->status()
        );
        self::assertSame(
            YSHelcimRefundResult::FAILED,
            YSHelcimProviderProof::classify($messageOnly, 'refund', 2100, 'USD')->status()
        );
        self::assertSame(
            YSHelcimRefundResult::FAILED,
            YSHelcimProviderProof::classify($similar, 'refund', 2100, 'USD')->status()
        );
        self::assertSame(
            YSHelcimRefundResult::FAILED,
            YSHelcimProviderProof::classify($structured, 'reverse', 2100, 'USD')->status()
        );
    }

    /**
     * @dataProvider impreciseAmounts
     */
    public function testProviderAmountMustConvertToExactCents(mixed $amount): void
    {
        $result = YSHelcimProviderProof::classify(
            [
                'status' => 'APPROVED',
                'type' => 'refund',
                'transactionId' => '51177123',
                'amount' => $amount,
                'currency' => 'USD',
            ],
            'refund',
            2100,
            'USD'
        );

        self::assertSame(YSHelcimRefundResult::INDETERMINATE, $result->status());
    }

    /**
     * @dataProvider exactAmounts
     */
    public function testOrdinaryProviderDecimalsConvertToExactCents(mixed $amount, int $expectedCents): void
    {
        self::assertSame($expectedCents, YSHelcimProviderProof::amountToCents($amount));
    }

    /** @return array<string, array{mixed}> */
    public static function invalidTransactionIds(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'zero int' => [0],
            'zero string' => ['0'],
            'negative int' => [-1],
            'negative string' => ['-1'],
            'letters' => ['abc'],
            'decimal' => ['12.5'],
            'boolean' => [true],
			'above platform integer' => [(string) PHP_INT_MAX . '0'],
        ];
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function mismatchedProofs(): array
    {
        $base = [
            'status' => 'APPROVED',
            'type' => 'refund',
            'transactionId' => '51177123',
            'amount' => 21.00,
            'currency' => 'USD',
        ];

        return [
            'wrong type' => [array_merge($base, ['type' => 'purchase'])],
            'wrong amount' => [array_merge($base, ['amount' => 20.99])],
            'wrong currency' => [array_merge($base, ['currency' => 'CAD'])],
            'missing amount' => [array_diff_key($base, ['amount' => true])],
            'missing currency' => [array_diff_key($base, ['currency' => true])],
        ];
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function ambiguousProviderResponses(): array
    {
        $complete = [
            'status' => 'DECLINED',
            'type' => 'refund',
            'transactionId' => '51177123',
            'amount' => 21.00,
            'currency' => 'USD',
        ];

        return [
            'missing status' => [array_diff_key($complete, ['status' => true])],
            'unknown status' => [array_merge($complete, ['status' => 'PENDING'])],
            'decline missing transaction id' => [array_diff_key($complete, ['transactionId' => true])],
            'decline wrong type' => [array_merge($complete, ['type' => 'purchase'])],
            'decline wrong amount' => [array_merge($complete, ['amount' => 20.99])],
            'decline wrong currency' => [array_merge($complete, ['currency' => 'CAD'])],
        ];
    }

    /** @return array<string, array{mixed}> */
    public static function impreciseAmounts(): array
    {
        return [
            'three decimals that round down' => [21.001],
            'three decimals that round up' => ['20.999'],
            'scientific notation string' => ['2.1e1'],
            'non-finite textual value' => ['INF'],
            'boolean' => [true],
        ];
    }

    /** @return array<string, array{mixed, int}> */
    public static function exactAmounts(): array
    {
        return [
            'two-place string' => ['21.00', 2100],
            'integer' => [21, 2100],
            'one-place float' => [21.0, 2100],
            'binary float edge' => [0.29, 29],
            'one-place string' => ['0.2', 20],
        ];
    }

    public function testAmountParserRejectsOverflowWithoutThrowing(): void
    {
        $maxDollars = intdiv(PHP_INT_MAX, 100);
        $maxFraction = PHP_INT_MAX % 100;
        $overflowFraction = min(99, $maxFraction + 1);

        self::assertSame(
            PHP_INT_MAX,
            YSHelcimProviderProof::amountToCents($maxDollars . '.' . str_pad((string) $maxFraction, 2, '0', STR_PAD_LEFT))
        );
        self::assertNull(
            YSHelcimProviderProof::amountToCents($maxDollars . '.' . str_pad((string) $overflowFraction, 2, '0', STR_PAD_LEFT))
        );
        self::assertNull(YSHelcimProviderProof::amountToCents(($maxDollars + 1) . '.00'));
    }

    /** @return array<string, array{\WP_Error}> */
    public static function indeterminateErrors(): array
    {
        return [
            'transport' => [new \WP_Error('ys_helcim_api_error', 'timeout', ['kind' => 'transport', 'indeterminate' => true])],
            'server' => [new \WP_Error('ys_helcim_api_error', 'server', ['kind' => 'http', 'http_code' => 503, 'indeterminate' => true])],
            'invalid json' => [new \WP_Error('ys_helcim_api_error', 'invalid', ['kind' => 'invalid_response', 'indeterminate' => true])],
        ];
    }
}
