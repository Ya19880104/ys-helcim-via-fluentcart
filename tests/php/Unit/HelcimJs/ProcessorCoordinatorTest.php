<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\HelcimJs;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use YangSheep\Helcim\FluentCart\HelcimJs\YSHelcimJsProcessor;
use YangSheep\Helcim\FluentCart\HelcimJs\YSHelcimJsPurchaseRuntime;
use YangSheep\Helcim\FluentCart\HelcimJs\YSHelcimJsSettings;
use YangSheep\Helcim\FluentCart\HelcimJs\YSHelcimPurchaseConfirmationToken;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationRepository;
use YangSheep\Helcim\FluentCart\Tests\Doubles\FakeWpdb;

#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
final class ProcessorCoordinatorTest extends TestCase
{
    private const OPERATION_UUID = '00000000-0000-4000-8000-000000000779';

    private YSHelcimJsSettings $settings;
    private YSHelcimOperationRepository $repository;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/Doubles/InlineFluentCart.php';
        require_once dirname(__DIR__, 2) . '/Doubles/InlineWordPress.php';
        OrderTransaction::reset();
        Order::reset();
        StatusHelper::reset();
        StoreSettings::$orderMode = 'test';
        BaseGatewaySettings::$settingsByClass[YSHelcimJsSettings::class] = [
            'test_api_token' => 'enc:test-api-secret',
            'test_js_secret_key' => 'enc:js-secret',
        ];
        $this->settings = new YSHelcimJsSettings();
        $this->repository = new YSHelcimOperationRepository(
            new FakeWpdb(),
            static fn (): string => '2026-07-21 00:00:00'
        );
        $this->seedModels();
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
    }

    public function testConfirmRoutesApprovedPurchaseThroughRuntimeAndReturnsOnlyVerifiedLocalSuccess(): void
    {
        $apiCalls = 0;
        $processor = new YSHelcimJsProcessor(
            $this->settings,
            $this->runtime(static function () use (&$apiCalls): array {
                $apiCalls++;
                return self::approved();
            })
        );
        $this->postVerifiedConfirm('verified-card-token');

        $response = $this->invoke($processor);

        self::assertSame(200, $response->statusCode);
        self::assertSame('success', $response->payload['status']);
        self::assertSame('https://shop.test/receipt/fc-transaction-123', $response->payload['redirect_url']);
        self::assertSame(1, $apiCalls);
        self::assertSame('51177123', OrderTransaction::allRecords()[20]['vendor_charge_id']);
        self::assertArrayNotHasKey('card_token', OrderTransaction::allRecords()[20]['meta']);
    }

    public function testIndeterminateConfirmIsNonRetryableAndRepeatedConfirmCannotRecharge(): void
    {
        $apiCalls = 0;
        $processor = new YSHelcimJsProcessor(
            $this->settings,
            $this->runtime(static function () use (&$apiCalls): \WP_Error {
                $apiCalls++;
                return new \WP_Error('ys_helcim_api_error', 'Timeout', [
                    'kind' => 'transport',
                    'indeterminate' => true,
                ]);
            })
        );
        $this->postVerifiedConfirm('unknown-card-token');

        $first = $this->invoke($processor);
        $second = $this->invoke($processor);

        self::assertSame(409, $first->statusCode);
        self::assertSame('pending', $first->payload['status']);
        self::assertFalse($first->payload['retry_allowed']);
        self::assertSame('pending', $second->payload['status']);
        self::assertSame(1, $apiCalls);
        self::assertCount(1, $this->repository->findPurchasesByIdentity(20));
        self::assertSame(Status::TRANSACTION_PENDING, OrderTransaction::allRecords()[20]['status']);
    }

    public function testStrictDeclineStaysUnpaidAndExplicitlyAllowsDifferentCard(): void
    {
        $processor = new YSHelcimJsProcessor(
            $this->settings,
            $this->runtime(static fn (): \WP_Error => new \WP_Error('ys_helcim_api_error', 'Declined', [
                'kind' => 'provider',
                'http_code' => 500,
                'indeterminate' => false,
                'provider_errors' => 'Transaction Declined: CVV does not match',
                'definitive_decline' => true,
            ]))
        );
        $this->postVerifiedConfirm('declined-card-token');

        $response = $this->invoke($processor);

        self::assertSame(402, $response->statusCode);
        self::assertSame('failed', $response->payload['status']);
        self::assertTrue($response->payload['retry_allowed']);
        self::assertSame(Status::TRANSACTION_PENDING, OrderTransaction::allRecords()[20]['status']);
    }

    public function testAuthenticationRejectionStaysUnpaidAndAllowsFreshPaymentAttempt(): void
    {
        $processor = new YSHelcimJsProcessor(
            $this->settings,
            $this->runtime(static fn (): \WP_Error => new \WP_Error('ys_helcim_api_error', 'Authentication failed', [
                'kind' => 'provider',
                'http_code' => 401,
                'indeterminate' => false,
                'mutation_disposition' => 'authentication_rejected',
            ]))
        );
        $this->postVerifiedConfirm('authentication-rejected-token');

        $response = $this->invoke($processor);

        self::assertSame(503, $response->statusCode);
        self::assertSame('failed', $response->payload['status']);
        self::assertTrue($response->payload['retry_allowed']);
        self::assertSame(Status::TRANSACTION_PENDING, OrderTransaction::allRecords()[20]['status']);
        self::assertNull(OrderTransaction::allRecords()[20]['vendor_charge_id']);
    }

    public function testPendingTransactionWithInvalidNonceCannotReachProvider(): void
    {
        $apiCalls = 0;
        $processor = new YSHelcimJsProcessor(
            $this->settings,
            $this->runtime(static function () use (&$apiCalls): array {
                ++$apiCalls;
                return self::approved();
            })
        );
        $this->postVerifiedConfirm('verified-card-token');
        $_POST['nonce'] = 'invalid-or-expired';

        $response = $this->invoke($processor);

        self::assertSame(403, $response->statusCode);
        self::assertSame('failed', $response->payload['status']);
        self::assertSame(0, $apiCalls);
        self::assertCount(0, $this->repository->findPurchasesByIdentity(20));
    }

    public function testPendingTransactionRequiresItsServerSignedConfirmationToken(): void
    {
        $apiCalls = 0;
        $processor = new YSHelcimJsProcessor(
            $this->settings,
            $this->runtime(static function () use (&$apiCalls): array {
                ++$apiCalls;
                return self::approved();
            })
        );
        $this->postVerifiedConfirm('verified-card-token');
        $_POST['confirm_token'] .= 'tampered';

        $response = $this->invoke($processor);

        self::assertSame(403, $response->statusCode);
        self::assertSame('failed', $response->payload['status']);
        self::assertSame(0, $apiCalls);
        self::assertCount(0, $this->repository->findPurchasesByIdentity(20));
    }

    #[DataProvider('arrayShapedPublicFields')]
    public function testArrayShapedPublicFieldsFailClosedWithoutTypeErrors(string $field, int $expectedStatus): void
    {
        $apiCalls = 0;
        $processor = new YSHelcimJsProcessor(
            $this->settings,
            $this->runtime(static function () use (&$apiCalls): array {
                ++$apiCalls;
                return self::approved();
            })
        );
        $this->postVerifiedConfirm('verified-card-token');
        $_POST[$field] = ['malformed'];

        $response = $this->invoke($processor);

        self::assertSame($expectedStatus, $response->statusCode);
        self::assertSame('failed', $response->payload['status']);
        self::assertSame(0, $apiCalls);
    }

    public static function arrayShapedPublicFields(): iterable
    {
        yield 'nonce' => ['nonce', 403];
        yield 'transaction UUID' => ['transaction_uuid', 422];
        yield 'response fields' => ['response_fields', 400];
    }

    public function testAuthenticatedSdkFailureResponseCannotReachTheProvider(): void
    {
        $apiCalls = 0;
        $processor = new YSHelcimJsProcessor(
            $this->settings,
            $this->runtime(static function () use (&$apiCalls): array {
                ++$apiCalls;
                return self::approved();
            })
        );
        $this->postVerifiedConfirm('failure-card-token', '0');

        $response = $this->invoke($processor);

        self::assertSame(422, $response->statusCode);
        self::assertSame('failed', $response->payload['status']);
        self::assertSame(0, $apiCalls);
        self::assertCount(0, $this->repository->findPurchasesByIdentity(20));
    }

    public function testResponseXmlHashUsesTheTransactionsStoredModeAfterStoreModeSwitch(): void
    {
        BaseGatewaySettings::$settingsByClass[YSHelcimJsSettings::class]['live_js_secret_key'] = 'enc:wrong-live-secret';
        StoreSettings::$orderMode = 'live';
        $apiCalls = 0;
        $processor = new YSHelcimJsProcessor(
            $this->settings,
            $this->runtime(static function () use (&$apiCalls): array {
                ++$apiCalls;
                return self::approved();
            })
        );
        $this->postVerifiedConfirm('stored-mode-card-token');

        $response = $this->invoke($processor);

        self::assertSame(200, $response->statusCode);
        self::assertSame('success', $response->payload['status']);
        self::assertSame(1, $apiCalls);
    }

    public function testMalformedAuthenticatedXmlCardTokenCannotReachTheProvider(): void
    {
        $apiCalls = 0;
        $processor = new YSHelcimJsProcessor(
            $this->settings,
            $this->runtime(static function () use (&$apiCalls): array {
                ++$apiCalls;
                return self::approved();
            })
        );
        $this->postVerifiedConfirm('short');

        $response = $this->invoke($processor);

        self::assertSame(422, $response->statusCode);
        self::assertSame('failed', $response->payload['status']);
        self::assertSame(0, $apiCalls);
    }

    public function testInvalidXmlHashCannotReachTheProvider(): void
    {
        $apiCalls = 0;
        $processor = new YSHelcimJsProcessor(
            $this->settings,
            $this->runtime(static function () use (&$apiCalls): array {
                ++$apiCalls;
                return self::approved();
            })
        );
        $this->postVerifiedConfirm('verified-card-token');
        $proof = json_decode((string) $_POST['response_fields'], true, 16, JSON_THROW_ON_ERROR);
        $proof['xmlHash'] = str_repeat('0', 64);
        $_POST['response_fields'] = json_encode($proof, JSON_THROW_ON_ERROR);

        $response = $this->invoke($processor);

        self::assertSame(400, $response->statusCode);
        self::assertSame('failed', $response->payload['status']);
        self::assertSame(0, $apiCalls);
    }

    public function testAlreadySucceededNeedsExactProviderIdButDoesNotNeedCardTokenOrHash(): void
    {
        $record = OrderTransaction::allRecords()[20];
        $record['status'] = Status::TRANSACTION_SUCCEEDED;
        $record['vendor_charge_id'] = '51177123';
        OrderTransaction::seed($record);
        $order = Order::query()->where('id', 10)->first();
        self::assertInstanceOf(Order::class, $order);
        $order->markPaid();
        $apiCalls = 0;
        $processor = new YSHelcimJsProcessor(
            $this->settings,
            $this->runtime(static function () use (&$apiCalls): array {
                $apiCalls++;
                return self::approved();
            })
        );
        $_POST = [
            'transaction_uuid' => 'fc-transaction-123',
            'nonce' => 'stale-after-login',
        ];

        $response = $this->invoke($processor);

        self::assertSame(200, $response->statusCode);
        self::assertSame('success', $response->payload['status']);
        self::assertSame(0, $apiCalls);

        $record['vendor_charge_id'] = 'malformed-id';
        OrderTransaction::seed($record);
        $invalid = $this->invoke($processor);
        self::assertSame(409, $invalid->statusCode);
        self::assertSame('pending', $invalid->payload['status']);
        self::assertFalse($invalid->payload['retry_allowed']);
        self::assertSame(0, $apiCalls);
    }

    public function testResponseFieldReaderKeepsOnlyTheOfficialXmlProofEnvelope(): void
    {
        $processor = new YSHelcimJsProcessor(
            $this->settings,
            $this->runtime(static fn (): array => self::approved())
        );
        $_POST['response_fields'] = json_encode([
            'xml' => '<message><response>1</response></message>',
            'xmlHash' => str_repeat('b', 64),
            'response' => '1',
            'cardNumber' => '5454****5454',
            'cardToken' => 'ephemeral-card-token',
            'cardCVV' => '100',
        ], JSON_THROW_ON_ERROR);

        $reader = new \ReflectionMethod($processor, 'readResponseFields');
        $fields = $reader->invoke($processor);

        self::assertSame([
            'xml' => '<message><response>1</response></message>',
            'xmlHash' => str_repeat('b', 64),
        ], $fields);
    }

    public function testOversizedResponseProofEnvelopeIsRejectedBeforeDecoding(): void
    {
        $processor = new YSHelcimJsProcessor(
            $this->settings,
            $this->runtime(static fn (): array => self::approved())
        );
        $_POST['response_fields'] = json_encode([
            'xml' => '<message>' . str_repeat('x', 70000) . '</message>',
            'xmlHash' => str_repeat('b', 64),
        ], JSON_THROW_ON_ERROR);

        $reader = new \ReflectionMethod($processor, 'readResponseFields');

        self::assertSame([], $reader->invoke($processor));
    }

    public function testSuccessReceiptFailsClosedWhenExactOrderUuidIsMissing(): void
    {
        $processor = new YSHelcimJsProcessor(
            $this->settings,
            $this->runtime(static fn (): array => self::approved())
        );
        $transaction = OrderTransaction::query()->where('id', 20)->first();
        self::assertInstanceOf(OrderTransaction::class, $transaction);
        Order::reset();

        $method = new \ReflectionMethod($processor, 'buildSuccessResponse');
        $result = $method->invoke($processor, $transaction, 'Paid');

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_confirm_receipt_missing', $result->get_error_code());
    }

    public function testClientIpTreatsArrayInputAsUntrusted(): void
    {
        $processor = new YSHelcimJsProcessor(
            $this->settings,
            $this->runtime(static fn (): array => self::approved())
        );
        $_SERVER['REMOTE_ADDR'] = ['203.0.113.7'];

        try {
            self::assertSame('127.0.0.1', $processor->getClientIp());
        } finally {
            unset($_SERVER['REMOTE_ADDR']);
        }
    }

    private function runtime(callable $apiRequest): YSHelcimJsPurchaseRuntime
    {
        return new YSHelcimJsPurchaseRuntime(
            settings: $this->settings,
            operations: $this->repository,
            api_request: $apiRequest,
            uuid_factory: static fn (): string => self::OPERATION_UUID,
            clock: static fn (): string => '2026-07-21 00:00:00',
            payload_filter: static fn (array $payload): array => $payload,
            client_ip: static fn (): string => '203.0.113.7'
        );
    }

    private function postVerifiedConfirm(string $cardToken, string $response = '1'): void
    {
        $xml = '<message>'
            . '<response>' . $response . '</response>'
            . '<responseMessage>' . ($response === '1' ? 'APPROVED' : 'DECLINED') . '</responseMessage>'
            . '<type>verify</type>'
            . '<cardToken>' . $cardToken . '</cardToken>'
            . '</message>';
        $_POST = [
            'transaction_uuid' => 'fc-transaction-123',
            'nonce' => 'nonce-ys_helcim_fct_confirm_js',
            'confirm_token' => (new YSHelcimPurchaseConfirmationToken())->issue('fc-transaction-123', 20),
            'response_fields' => json_encode([
                'xml' => $xml,
                'xmlHash' => hash('sha256', 'js-secret' . preg_replace('/\s+/', '', $xml)),
            ], JSON_THROW_ON_ERROR),
        ];
    }

    private function invoke(YSHelcimJsProcessor $processor): \YSHelcimWpJsonExit
    {
        try {
            $processor->handleConfirmRequest();
            self::fail('handleConfirmRequest must terminate through wp_send_json.');
        } catch (\YSHelcimWpJsonExit $response) {
            return $response;
        }
    }

    private function seedModels(): void
    {
        OrderTransaction::seed([
            'id' => 20,
            'uuid' => 'fc-transaction-123',
            'order_id' => 10,
            'payment_method' => 'ys_helcim_js',
            'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
            'total' => 2100,
            'currency' => 'USD',
            'payment_mode' => 'test',
            'status' => Status::TRANSACTION_PENDING,
            'vendor_charge_id' => null,
            'payment_method_type' => '',
            'card_last_4' => '',
            'card_brand' => '',
            'meta' => [],
        ]);
        Order::seed([
            'id' => 10,
            'uuid' => 'order-uuid-10',
            'status' => 'pending',
            'payment_status' => 'pending',
            'total_amount' => 2100,
            'total_paid' => 0,
            'billing_address' => null,
            'shipping_address' => null,
            'customer' => null,
        ]);
    }

    /** @return array<string, mixed> */
    private static function approved(): array
    {
        return [
            'status' => 'APPROVED',
            'type' => 'purchase',
            'transactionId' => '51177123',
            'amount' => '21.00',
            'currency' => 'USD',
        ];
    }
}
