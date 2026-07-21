<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Support\YSHelcimApiClient;
use YangSheep\Helcim\FluentCart\Support\YSHelcimLogger;

final class ApiClientFailureClassificationTest extends TestCase
{
    protected function setUp(): void
    {
        \YSHelcimWpDouble::reset();
    }

    public function testEmptyApiTokenIsProvenNeverSent(): void
    {
        $result = YSHelcimApiClient::request(
            'payment/purchase',
            [],
            '',
            'ysh-' . str_repeat('a', 32)
        );

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('local', $result->get_error_data()['kind']);
        self::assertFalse($result->get_error_data()['indeterminate']);
        self::assertSame('never_sent', $result->get_error_data()['mutation_disposition']);
        self::assertCount(0, \YSHelcimWpDouble::$requests);
    }

    public function testInvalidIdempotencyKeyIsProvenNeverSent(): void
    {
        $result = YSHelcimApiClient::request(
            'payment/purchase',
            [],
            'test-api-token',
            'too-short'
        );

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_invalid_idempotency_key', $result->get_error_code());
        self::assertSame('never_sent', $result->get_error_data()['mutation_disposition']);
        self::assertCount(0, \YSHelcimWpDouble::$requests);
    }

    public function testPurchaseAuthenticationRejectionIsProvenNotAccepted(): void
    {
        \YSHelcimWpDouble::$response = [
            'response' => ['code' => 401],
            'body' => '{"errors":"Authentication failed"}',
        ];

        $result = YSHelcimApiClient::request(
            'payment/purchase',
            [],
            'inactive-api-token',
            'ysh-' . str_repeat('a', 32)
        );

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('provider', $result->get_error_data()['kind']);
        self::assertSame(401, $result->get_error_data()['http_code']);
        self::assertFalse($result->get_error_data()['indeterminate']);
        self::assertSame('authentication_rejected', $result->get_error_data()['mutation_disposition']);
        self::assertCount(1, \YSHelcimWpDouble::$requests);
    }

    /** @dataProvider nonAllowlistedClientErrorProvider */
    public function testOtherClientErrorsDoNotClaimTheMutationWasRejected(int $httpCode): void
    {
        \YSHelcimWpDouble::$response = [
            'response' => ['code' => $httpCode],
            'body' => '{"errors":"Request rejected"}',
        ];

        $result = YSHelcimApiClient::request(
            'payment/purchase',
            [],
            'test-api-token',
            'ysh-' . str_repeat('a', 32)
        );

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('outcome_unknown', $result->get_error_data()['mutation_disposition']);
    }

    /** @return array<string,array{int}> */
    public static function nonAllowlistedClientErrorProvider(): array
    {
        return [
            'bad request' => [400],
            'forbidden' => [403],
            'unprocessable entity' => [422],
        ];
    }

    public function testTransportFailureIsIndeterminate(): void
    {
        \YSHelcimWpDouble::$response = new \WP_Error('http_request_failed', 'Connection timed out');

        $result = YSHelcimApiClient::request('card-transactions/123', [], 'test-api-token', null, 'GET');

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('transport', $result->get_error_data()['kind']);
        self::assertTrue($result->get_error_data()['indeterminate']);
        self::assertSame('outcome_unknown', $result->get_error_data()['mutation_disposition']);
    }

    public function testServerFailureIsIndeterminate(): void
    {
        \YSHelcimWpDouble::$response = [
            'response' => ['code' => 503],
            'body' => '{"errors":"Service unavailable"}',
        ];

        $result = YSHelcimApiClient::request('card-transactions/123', [], 'test-api-token', null, 'GET');

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('http', $result->get_error_data()['kind']);
        self::assertSame(503, $result->get_error_data()['http_code']);
        self::assertTrue($result->get_error_data()['indeterminate']);
    }

    public function testDocumentedPurchaseDeclineResponseIsTheOnlyDefinitiveHttp500Outcome(): void
    {
        \YSHelcimWpDouble::$response = [
            'response' => ['code' => 500],
            'body' => '{"response":0,"errors":"Transaction Declined: DECLINE CVV2 - Do not honor due to CVV2 mismatch/failure"}',
        ];

        $result = YSHelcimApiClient::request(
            'payment/purchase',
            [],
            'test-api-token',
            'ysh-' . str_repeat('a', 32)
        );

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('provider', $result->get_error_data()['kind']);
        self::assertFalse($result->get_error_data()['indeterminate']);
        self::assertTrue($result->get_error_data()['definitive_decline']);
        self::assertSame('definitive_decline', $result->get_error_data()['mutation_disposition']);
        self::assertSame(
            'Transaction Declined: DECLINE CVV2 - Do not honor due to CVV2 mismatch/failure',
            $result->get_error_data()['provider_errors']
        );
    }

    /** @dataProvider nonDefinitiveServerErrorProvider */
    public function testUnknownOrIncompleteHttp500ResponsesRemainIndeterminate(string $endpoint, string $body): void
    {
        \YSHelcimWpDouble::$response = ['response' => ['code' => 500], 'body' => $body];

        $result = YSHelcimApiClient::request(
            $endpoint,
            [],
            'test-api-token',
            'ysh-' . str_repeat('a', 32)
        );

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertTrue($result->get_error_data()['indeterminate']);
        self::assertArrayNotHasKey('definitive_decline', $result->get_error_data());
    }

    /** @return array<string,array{string,string}> */
    public static function nonDefinitiveServerErrorProvider(): array
    {
        return [
            'missing response zero' => ['payment/purchase', '{"errors":"Transaction Declined: DECLINE CVV2 - mismatch"}'],
            'non-decline error' => ['payment/purchase', '{"response":0,"errors":"Internal server error"}'],
            'wrong mutation type' => ['payment/refund', '{"response":0,"errors":"Transaction Declined: DECLINED - Do Not Honor"}'],
            'multiple error fields' => ['payment/purchase', '{"response":0,"errors":{"one":"Transaction Declined: DECLINED","two":"Service unavailable"}}'],
        ];
    }

    public function testStructuredClientErrorRetainsOnlyProviderErrorsForClassification(): void
    {
        \YSHelcimWpDouble::$response = [
            'response' => ['code' => 422],
            'body' => '{"errors":{"cardTransactionId":"Card Transaction cannot be refunded"}}',
        ];

        $result = YSHelcimApiClient::request(
            'payment/refund',
            [],
            'test-api-token',
            'ysh-' . str_repeat('a', 32)
        );

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('provider', $result->get_error_data()['kind']);
        self::assertSame(422, $result->get_error_data()['http_code']);
        self::assertFalse($result->get_error_data()['indeterminate']);
        self::assertSame(
            ['cardtransactionid' => 'Card Transaction cannot be refunded'],
            $result->get_error_data()['provider_errors']
        );
    }

    public function testSuccessfulHttpWithInvalidJsonIsIndeterminate(): void
    {
        \YSHelcimWpDouble::$response = [
            'response' => ['code' => 200],
            'body' => '<html>not json</html>',
        ];

        $result = YSHelcimApiClient::request('card-transactions/123', [], 'test-api-token', null, 'GET');

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('invalid_response', $result->get_error_data()['kind']);
        self::assertTrue($result->get_error_data()['indeterminate']);
    }

    public function testClientErrorWithInvalidJsonIsIndeterminate(): void
    {
        \YSHelcimWpDouble::$response = [
            'response' => ['code' => 422],
            'body' => '<html>proxy response</html>',
        ];

        $result = YSHelcimApiClient::request(
            'payment/refund',
            [],
            'test-api-token',
            'ysh-' . str_repeat('a', 32)
        );

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('invalid_response', $result->get_error_data()['kind']);
        self::assertSame(422, $result->get_error_data()['http_code']);
        self::assertTrue($result->get_error_data()['indeterminate']);
    }

    public function testConflictResponseIsIndeterminateBecauseItMayReferToAnAcceptedIdempotentRequest(): void
    {
        \YSHelcimWpDouble::$response = [
            'response' => ['code' => 409],
            'body' => '{"errors":["Idempotency key conflicts with an existing transaction"]}',
        ];

        $result = YSHelcimApiClient::request(
            'payment/refund',
            [],
            'test-api-token',
            'ysh-' . str_repeat('a', 32)
        );

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('conflict', $result->get_error_data()['kind']);
        self::assertSame(409, $result->get_error_data()['http_code']);
        self::assertTrue($result->get_error_data()['indeterminate']);
    }

    /**
     * @dataProvider ambiguousHttpStatusProvider
     */
    public function testAmbiguousHttpStatusNeverLooksSuccessful(int $httpCode): void
    {
        \YSHelcimWpDouble::$response = [
            'response' => ['code' => $httpCode],
            'body' => '{"errors":"Gateway did not provide a final outcome"}',
        ];

        $result = YSHelcimApiClient::request(
            'payment/refund',
            [],
            'test-api-token',
            'ysh-' . str_repeat('a', 32)
        );

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame($httpCode, $result->get_error_data()['http_code']);
        self::assertTrue($result->get_error_data()['indeterminate']);
    }

    /** @return array<string,array{int}> */
    public static function ambiguousHttpStatusProvider(): array
    {
        return [
            'missing status' => [0],
            'redirect' => [302],
            'request timeout' => [408],
            'too early' => [425],
            'rate limited' => [429],
        ];
    }

    public function testProviderErrorMessageAndStructureNeverExposeMarkupSecretsOrPan(): void
    {
        \YSHelcimWpDouble::$response = [
            'response' => ['code' => 422],
            'body' => json_encode([
                'errors' => [
                    'cardTransactionId' => '<img src=x onerror=alert(1)> api-token=secret-value 4111111111111111',
                    'cardCVV' => '123',
                    'cardExpiry' => '1228',
                    'apiToken' => 'helcim-secret',
                ],
            ], JSON_THROW_ON_ERROR),
        ];

        $result = YSHelcimApiClient::request(
            'payment/refund',
            [],
            'test-api-token',
            'ysh-' . str_repeat('a', 32)
        );

        self::assertInstanceOf(\WP_Error::class, $result);
        $serialized = json_encode([$result->get_error_message(), $result->get_error_data()], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('<img', $serialized);
        self::assertStringNotContainsString('secret-value', $serialized);
        self::assertStringNotContainsString('4111111111111111', $serialized);
        self::assertStringNotContainsString('"123"', $serialized);
        self::assertStringNotContainsString('1228', $serialized);
        self::assertStringNotContainsString('helcim-secret', $serialized);
    }

    public function testProductionErrorLogRedactsProviderCustomerPii(): void
    {
        $privateValues = [
            'Private Buyer',
            'private@example.test',
            '123 Private Street',
            '+1-555-0100',
        ];
        \YSHelcimWpDouble::$response = [
            'response' => ['code' => 422],
            'body' => json_encode([
                'errors' => 'Request rejected',
                'billingAddress' => [
                    'name' => $privateValues[0],
                    'email' => $privateValues[1],
                    'street1' => $privateValues[2],
                ],
                'customer' => ['phone' => $privateValues[3]],
            ], JSON_THROW_ON_ERROR),
        ];
        $logFile = tempnam(sys_get_temp_dir(), 'ys-helcim-log-');
        self::assertIsString($logFile);
        $previousLog = ini_get('error_log');
        ini_set('error_log', $logFile);
        YSHelcimLogger::set_enabled(false);

        try {
            YSHelcimApiClient::request('payment/refund', [], 'test-api-token', 'ysh-' . str_repeat('a', 32));
            $log = (string) file_get_contents($logFile);
        } finally {
            ini_set('error_log', is_string($previousLog) ? $previousLog : '');
            @unlink($logFile);
        }

        foreach ($privateValues as $privateValue) {
            self::assertStringNotContainsString($privateValue, $log);
        }
        self::assertStringContainsString('[redacted]', $log);
    }
}
