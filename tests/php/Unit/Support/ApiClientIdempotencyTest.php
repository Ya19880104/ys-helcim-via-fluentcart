<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimIdempotency;
use YangSheep\Helcim\FluentCart\Support\YSHelcimApiClient;

final class ApiClientIdempotencyTest extends TestCase
{
    protected function setUp(): void
    {
        \YSHelcimWpDouble::reset();
    }

    public function testInvalidIdempotencyKeyIsRejectedBeforeTransport(): void
    {
        $result = YSHelcimApiClient::request('payment/refund', [], 'test-api-token', 'too-short');

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_invalid_idempotency_key', $result->get_error_code());
        self::assertSame([], \YSHelcimWpDouble::$requests);
    }

    /**
     * @dataProvider invalidApiKeys
     */
    public function testEveryInvalidNonNullKeyIsRejectedBeforeTransport(string $key): void
    {
        $result = YSHelcimApiClient::request('payment/refund', [], 'test-api-token', $key);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_invalid_idempotency_key', $result->get_error_code());
        self::assertSame([], \YSHelcimWpDouble::$requests);
    }

    public function testPaymentMutationEndpointsRequireAnIdempotencyKey(): void
    {
        foreach (['payment/purchase', 'payment/refund', 'payment/reverse'] as $endpoint) {
            $result = YSHelcimApiClient::request($endpoint, [], 'test-api-token');

            self::assertInstanceOf(\WP_Error::class, $result);
            self::assertSame('ys_helcim_invalid_idempotency_key', $result->get_error_code());
        }

        self::assertSame([], \YSHelcimWpDouble::$requests);
    }

    /**
     * @dataProvider validApiKeys
     */
    public function testValidIdempotencyKeyIsForwardedUnchanged(string $key): void
    {
        $result = YSHelcimApiClient::request('payment/refund', [], 'test-api-token', $key);

        self::assertSame([], $result);
        self::assertCount(1, \YSHelcimWpDouble::$requests);
        self::assertSame($key, \YSHelcimWpDouble::$requests[0]['args']['headers']['idempotency-key']);
    }

    public function testReadOnlyAndInitializeEndpointsMayOmitAKey(): void
    {
        YSHelcimApiClient::request('card-transactions/123', [], 'test-api-token', null, 'GET');
        YSHelcimApiClient::request('helcim-pay/initialize', [], 'test-api-token');

        self::assertCount(2, \YSHelcimWpDouble::$requests);
        self::assertArrayNotHasKey('idempotency-key', \YSHelcimWpDouble::$requests[0]['args']['headers']);
        self::assertArrayNotHasKey('idempotency-key', \YSHelcimWpDouble::$requests[1]['args']['headers']);
    }

    /** @return array<string, array{string}> */
    public static function invalidApiKeys(): array
    {
        return [
            'empty' => [''],
            '21 characters' => [str_repeat('a', 21)],
            '24 characters' => [str_repeat('a', 24)],
            '37 characters' => [str_repeat('a', 37)],
            'space' => [str_repeat('a', 24) . ' '],
            'control character' => [str_repeat('a', 24) . "\n"],
        ];
    }

    /** @return array<string, array{string}> */
    public static function validApiKeys(): array
    {
        return [
            '25 character boundary' => [str_repeat('a', 25)],
            '36 character boundary' => ['ysh-' . str_repeat('b', 32)],
        ];
    }
}
