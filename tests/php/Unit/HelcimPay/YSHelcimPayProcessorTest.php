<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\HelcimPay;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use FluentCart\App\Services\Payments\PaymentInstance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\HelcimPay\YSHelcimPayProcessor;
use YangSheep\Helcim\FluentCart\HelcimPay\YSHelcimPaySettings;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationRepository;
use YangSheep\Helcim\FluentCart\Tests\Doubles\FakeWpdb;

#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
final class YSHelcimPayProcessorTest extends TestCase
{
    private const OPERATION_UUID = '00000000-0000-4000-8000-000000000741';
    private const CONFIRM_TOKEN = 'hosted-confirm-token-with-thirty-two-chars-741';
    private const SECRET_TOKEN = 'hosted-provider-secret-741';

    private FakeWpdb $database;
    private YSHelcimOperationRepository $repository;
    private YSHelcimPayProcessor $processor;
    /** @var array<int,array<string,mixed>> */
    private array $apiCalls = [];

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/Doubles/InlineFluentCart.php';
        require_once dirname(__DIR__, 2) . '/Doubles/InlineWordPress.php';
        require_once dirname(__DIR__, 2) . '/Doubles/GatewayPaymentInstance.php';

        \YSHelcimWpDouble::reset();
        OrderTransaction::reset();
        Order::reset();
        StatusHelper::reset();
        Helper::$encryptionAvailable = true;
        StoreSettings::$orderMode = 'test';
        BaseGatewaySettings::$settingsByClass[YSHelcimPaySettings::class] = [
            'test_api_token' => 'enc:test-api-secret',
        ];
        $this->seedModels();
        $this->database = new FakeWpdb();
        $this->repository = new YSHelcimOperationRepository(
            $this->database,
            static fn (): string => '2026-07-22 00:00:00'
        );

        $this->processor = new YSHelcimPayProcessor(
            new YSHelcimPaySettings(),
            operations: $this->repository,
            api_request: function (
                string $endpoint,
                array $payload,
                string $apiToken,
                ?string $idempotencyKey = null,
                string $method = 'POST'
            ): array {
                $row = $this->repository->findByUuid(self::OPERATION_UUID);
                $this->apiCalls[] = compact('endpoint', 'payload', 'apiToken', 'idempotencyKey', 'method', 'row');
                return [
                    'checkoutToken' => 'hosted-checkout-token-741',
                    'secretToken' => self::SECRET_TOKEN,
                ];
            },
            uuid_factory: static fn (): string => self::OPERATION_UUID,
            confirm_token_factory: static fn (): string => self::CONFIRM_TOKEN,
            initialization_clock: static fn (): int => strtotime('2026-07-22 00:00:00 UTC')
        );
    }

    protected function tearDown(): void
    {
        $_POST = [];
    }

    public function testInitializePersistsAndClaimsBeforeProviderAndExposesOnlyDurableBrowserData(): void
    {
        $result = $this->processor->initialize($this->paymentInstance());

        self::assertIsArray($result);
        self::assertSame('success', $result['status']);
        self::assertSame('custom', $result['actionName']);
        self::assertSame('ys_helcim', $result['nextAction']);
        self::assertSame([
            'checkout_token' => 'hosted-checkout-token-741',
            'transaction_uuid' => 'fc-hosted-transaction-741',
            'operation_uuid' => self::OPERATION_UUID,
            'confirm_token' => self::CONFIRM_TOKEN,
            'confirm_nonce' => 'nonce-' . YSHelcimPayProcessor::NONCE_ACTION,
            'mode' => 'test',
        ], $result['payment_data']);
        self::assertArrayNotHasKey('secret_token', $result['payment_data']);

        self::assertCount(1, $this->apiCalls);
        self::assertSame('helcim-pay/initialize', $this->apiCalls[0]['endpoint']);
        self::assertSame('test-api-secret', $this->apiCalls[0]['apiToken']);
        self::assertNull($this->apiCalls[0]['idempotencyKey']);
        self::assertSame('POST', $this->apiCalls[0]['method']);
        self::assertSame('processing', $this->apiCalls[0]['row']['remote_status']);
        self::assertSame(self::OPERATION_UUID, $this->apiCalls[0]['row']['provider_correlation_id']);
        self::assertArrayNotHasKey('invoiceNumber', $this->apiCalls[0]['payload']);
        self::assertSame(21.0, $this->apiCalls[0]['payload']['amount']);
        self::assertSame([
            'invoiceNumber' => self::OPERATION_UUID,
            'lineItems' => [[
                'sku' => 'YSFC-20',
                'description' => 'FluentCart order 10',
                'quantity' => 1,
                'price' => 21.0,
                'total' => 21.0,
            ]],
        ], $this->apiCalls[0]['payload']['invoiceRequest']);

        $meta = OrderTransaction::allRecords()[20]['meta'];
        self::assertSame('kept', $meta['existing']);
        self::assertSame('hosted-checkout-token-741', $meta['ys_helcim_checkout_token']);
        self::assertSame('enc:' . self::SECRET_TOKEN, $meta['ys_helcim_secret_token_enc']);
        self::assertSame(self::OPERATION_UUID, $meta['ys_helcim_operation_uuid']);
        self::assertArrayNotHasKey('ys_helcim_card_token', $meta);
    }

    public function testInitializeDoesNotExposeProviderTokensWhenTransactionMetaCannotBePersisted(): void
    {
        OrderTransaction::$saveResult = false;

        $result = $this->processor->initialize($this->paymentInstance());

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_initialize_persistence_failed', $result->get_error_code());
        self::assertCount(1, $this->apiCalls);
        $row = $this->repository->findByUuid(self::OPERATION_UUID);
        self::assertSame('failed', $row['remote_status']);
        self::assertNull($row['active_scope_key']);
        self::assertSame(Status::TRANSACTION_PENDING, OrderTransaction::allRecords()[20]['status']);
    }

    public function testInitializeFailsClosedWithoutPersistingPlaintextWhenFluentCartEncryptionIsUnavailable(): void
    {
        Helper::$encryptBehavior = 'plaintext';

        $result = $this->processor->initialize($this->paymentInstance());

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_initialize_persistence_failed', $result->get_error_code());
        $serialized = json_encode(OrderTransaction::allRecords(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::SECRET_TOKEN, $serialized);
        self::assertArrayNotHasKey('ys_helcim_secret_token_enc', OrderTransaction::allRecords()[20]['meta']);
        self::assertSame('failed', $this->repository->findByUuid(self::OPERATION_UUID)['remote_status']);
    }

    public function testUnexposedSessionRemainsLockedWhenItsFailedTransitionCannotBePersisted(): void
    {
        OrderTransaction::$saveResult = false;
        $processor = $this->processorWithApi(function (): array {
            $this->database->failNextUpdate = true;
            return [
                'checkoutToken' => 'hosted-checkout-token-741',
                'secretToken' => self::SECRET_TOKEN,
            ];
        });

        $result = $processor->initialize($this->paymentInstance());

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_journal_outcome_unpersisted', $result->get_error_code());
        $stored = $this->repository->findByUuid(self::OPERATION_UUID);
        self::assertSame('processing', $stored['remote_status']);
        self::assertSame($stored['scope_key'], $stored['active_scope_key']);
    }

    public function testUnexposedSessionDoesNotClaimFailureWhenTerminalReadbackIsLost(): void
    {
        $armed = false;
        $this->database = new FakeWpdb();
        $this->repository = new YSHelcimOperationRepository(
            $this->database,
            function () use (&$armed): string {
                if ($armed) {
                    $armed = false;
                    $this->database->failNextLookup = true;
                }
                return '2026-07-22 00:00:00';
            }
        );
        OrderTransaction::$saveResult = false;
        $processor = $this->processorWithApi(function () use (&$armed): array {
            $armed = true;
            return [
                'checkoutToken' => 'hosted-checkout-token-741',
                'secretToken' => self::SECRET_TOKEN,
            ];
        });

        $result = $processor->initialize($this->paymentInstance());

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_journal_outcome_unpersisted', $result->get_error_code());
        $stored = $this->repository->findByUuid(self::OPERATION_UUID);
        self::assertSame('failed', $stored['remote_status']);
        self::assertNull($stored['active_scope_key']);
    }

    #[DataProvider('invalidRawProviderResponses')]
    public function testInitializeRejectsNonStringOrMalformedRawProviderTokens(mixed $providerResponse): void
    {
        $processor = $this->processorWithApi(
            static fn (): mixed => $providerResponse
        );

        $result = $processor->initialize($this->paymentInstance());

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_initialize_indeterminate', $result->get_error_code());
        $stored = $this->repository->findByUuid(self::OPERATION_UUID);
        self::assertSame('indeterminate', $stored['remote_status']);
        self::assertSame($stored['scope_key'], $stored['active_scope_key']);
    }

    public static function invalidRawProviderResponses(): iterable
    {
        yield 'integer checkout token' => [[
            'checkoutToken' => 123456,
            'secretToken' => self::SECRET_TOKEN,
        ]];
        yield 'boolean secret token' => [[
            'checkoutToken' => 'hosted-checkout-token-741',
            'secretToken' => true,
        ]];
        yield 'array checkout token' => [[
            'checkoutToken' => ['unexpected'],
            'secretToken' => self::SECRET_TOKEN,
        ]];
        yield 'whitespace secret token' => [[
            'checkoutToken' => 'hosted-checkout-token-741',
            'secretToken' => '   ',
        ]];
        yield 'unexpected response key' => [[
            'checkoutToken' => 'hosted-checkout-token-741',
            'secretToken' => self::SECRET_TOKEN,
            'extra' => 'must-not-be-accepted',
        ]];
    }

    public function testMissingCurrentModeCredentialIsProvenNeverSentAndCanBeRetried(): void
    {
        BaseGatewaySettings::$settingsByClass[YSHelcimPaySettings::class] = [];
        $providerCalls = 0;
        $processor = $this->processorWithApi(
            static function () use (&$providerCalls): array {
                ++$providerCalls;
                return [];
            }
        );

        $result = $processor->initialize($this->paymentInstance());

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_initialize_credential_missing', $result->get_error_code());
        self::assertSame(0, $providerCalls);
        $stored = $this->repository->findByUuid(self::OPERATION_UUID);
        self::assertSame('failed', $stored['remote_status']);
        self::assertNull($stored['active_scope_key']);
    }

    public function testLegacyInitializeFilterReceivesPaymentInstanceAndImmutableFieldsAreReasserted(): void
    {
        $paymentInstance = $this->paymentInstance();
        $legacyArgument = null;
        $v2Identity = null;
        $v2Correlation = null;
        add_filter(
            'ys_helcim_fct_initialize_args',
            static function (array $payload, mixed $argument) use (&$legacyArgument): array {
                $legacyArgument = $argument;
                $payload['paymentType'] = 'verify';
                $payload['amount'] = 9999;
                $payload['currency'] = 'CAD';
                $payload['paymentMethod'] = 'ach';
                $payload['invoiceRequest'] = ['invoiceNumber' => 'tampered'];
                $payload['confirmationScreen'] = false;
                return $payload;
            },
            10,
            2
        );
        add_filter(
            'ys_helcim_fct_initialize_args_v2',
            static function (array $payload, array $identity, string $correlation) use (&$v2Identity, &$v2Correlation): array {
                $v2Identity = $identity;
                $v2Correlation = $correlation;
                return $payload;
            },
            10,
            3
        );

        $result = $this->processor->initialize($paymentInstance);

        self::assertIsArray($result);
        self::assertSame($paymentInstance, $legacyArgument);
        self::assertSame(self::OPERATION_UUID, $v2Correlation);
        self::assertSame('fc-hosted-transaction-741', $v2Identity['transaction_uuid']);
        $payload = $this->apiCalls[0]['payload'];
        self::assertSame('purchase', $payload['paymentType']);
        self::assertSame(21.0, $payload['amount']);
        self::assertSame('USD', $payload['currency']);
        self::assertSame('cc', $payload['paymentMethod']);
        self::assertSame(self::OPERATION_UUID, $payload['invoiceRequest']['invoiceNumber']);
        self::assertFalse($payload['confirmationScreen']);
    }

    /**
     * Helcim rejects a nested object for digitalWallet with HTTP 400
     * ("digital Wallet must be a valid Non-empty String"), which would stop the
     * checkout modal from opening at all, so the value must stay a JSON string.
     *
     * @dataProvider googlePayPreferences
     */
    public function testGooglePayPreferenceIsSentAsAJsonStringOrOmittedEntirely(
        ?string $setting,
        ?string $expected
    ): void {
        $settings = ['test_api_token' => 'enc:test-api-secret'];
        if (null !== $setting) {
            $settings['google_pay'] = $setting;
        }
        BaseGatewaySettings::$settingsByClass[YSHelcimPaySettings::class] = $settings;

        $result = $this->processorWithCurrentSettings()->initialize($this->paymentInstance());

        self::assertIsArray($result);
        $payload = $this->apiCalls[0]['payload'];

        if (null === $expected) {
            self::assertArrayNotHasKey('digitalWallet', $payload);
            return;
        }

        self::assertIsString($payload['digitalWallet']);
        self::assertSame($expected, $payload['digitalWallet']);
    }

    /** @return array<string, array{0: ?string, 1: ?string}> */
    public static function googlePayPreferences(): array
    {
        return [
            'unset inherits the Helcim account default' => [null, null],
            'account inherits the Helcim account default' => ['account', null],
            'on overrides the account default' => ['on', '{"google-pay":1}'],
            'off overrides the account default' => ['off', '{"google-pay":0}'],
            'an unknown value falls back to the account default' => ['sometimes', null],
        ];
    }

    private const SECOND_OPERATION_UUID = '00000000-0000-4000-8000-000000000742';
    private const SECOND_CONFIRM_TOKEN = 'hosted-confirm-token-with-thirty-two-chars-742';

    /**
     * A shopper closes the modal (or opens a second tab) and presses pay again while the
     * Helcim session is still young: the SAME session must be re-exposed — same checkout
     * token, same operation, no release, no new provider session — with only the one-time
     * confirm token rotated so the older browser can no longer confirm.
     */
    public function testInitializeResumesAYoungBlockingSessionInsteadOfReleasingIt(): void
    {
        self::assertIsArray($this->processor->initialize($this->paymentInstance()));

        $second = $this->scopeAwareProcessor(self::SECOND_OPERATION_UUID);
        $result = $second->initialize($this->paymentInstance());

        self::assertIsArray($result, 'the blocked attempt must resume the existing session');
        self::assertSame(self::OPERATION_UUID, $result['payment_data']['operation_uuid'], 'the ORIGINAL operation is resumed');
        self::assertSame('hosted-checkout-token-741', $result['payment_data']['checkout_token'], 'the SAME provider session is re-exposed');

        $blocker = $this->repository->findByUuid(self::OPERATION_UUID);
        self::assertSame('processing', $blocker['remote_status'], 'a live session is never released');
        self::assertNotNull($blocker['active_scope_key']);
        self::assertNull($this->repository->findByUuid(self::SECOND_OPERATION_UUID), 'no second operation may exist');

        $newToken = (string) $result['payment_data']['confirm_token'];
        self::assertNotSame(self::CONFIRM_TOKEN, $newToken);
        self::assertFalse(
            $this->repository->consumeConfirmToken(self::OPERATION_UUID, self::CONFIRM_TOKEN),
            'the previously issued confirm token must stop working'
        );
        self::assertTrue(
            $this->repository->consumeConfirmToken(self::OPERATION_UUID, $newToken),
            'only the freshly rotated confirm token may confirm'
        );

        $providerCalls = array_values(array_filter(
            $this->apiCalls,
            static fn (array $call): bool => in_array($call['endpoint'], ['card-transactions', 'helcim-pay/initialize'], true)
        ));
        self::assertCount(1, $providerCalls, 'resume makes NO provider request (the one call is the first initialize)');
        self::assertSame('helcim-pay/initialize', $providerCalls[0]['endpoint']);
    }

    /**
     * Only a session past Helcim's own validity window may be verified and released, and
     * the release target is canceled (not failed) so exact late proof can still bind.
     * Indeterminate = bounded recovery already observed the expiry.
     */
    public function testInitializeReleasesAnExpiredIndeterminateBlockerToCanceledAndRetries(): void
    {
        self::assertIsArray($this->processor->initialize($this->paymentInstance()));
        self::assertTrue(
            $this->repository->transitionRemote(self::OPERATION_UUID, 'processing', 'indeterminate')
        );

        $second = $this->scopeAwareProcessor(self::SECOND_OPERATION_UUID, [], self::clockAtAge(4200));
        $result = $second->initialize($this->paymentInstance());

        self::assertIsArray($result);
        self::assertSame(self::SECOND_OPERATION_UUID, $result['payment_data']['operation_uuid']);

        $blocker = $this->repository->findByUuid(self::OPERATION_UUID);
        self::assertSame('canceled', $blocker['remote_status'], 'released checkouts stay reachable for late proof');
        self::assertNull($blocker['active_scope_key']);
        self::assertSame('ys_helcim_session_expired_released', $blocker['remote_error_code']);
        self::assertNotNull($blocker['next_recovery_at'], 'a released checkout schedules exactly one late-proof follow-up');

        $lookups = array_values(array_filter(
            $this->apiCalls,
            static fn (array $call): bool => 'card-transactions' === $call['endpoint']
        ));
        self::assertCount(2, $lookups, 'release requires two consecutive charge-detection reads');
        self::assertSame(self::OPERATION_UUID, $lookups[0]['payload']['invoiceNumber']);

        $fresh = $this->repository->findByUuid(self::SECOND_OPERATION_UUID);
        self::assertSame('processing', $fresh['remote_status']);
        self::assertNotNull($fresh['active_scope_key']);
    }

    /** An expired blocker with any provider transaction must stay locked and open no new session. */
    public function testInitializeRefusesWhenTheExpiredBlockerHasAProviderTransaction(): void
    {
        self::assertIsArray($this->processor->initialize($this->paymentInstance()));
        self::assertTrue(
            $this->repository->transitionRemote(self::OPERATION_UUID, 'processing', 'indeterminate')
        );

        $second = $this->scopeAwareProcessor(self::SECOND_OPERATION_UUID, [['transactionId' => 51900001]], self::clockAtAge(4200));
        $result = $second->initialize($this->paymentInstance());

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_previous_attempt_needs_review', $result->get_error_code());

        $blocker = $this->repository->findByUuid(self::OPERATION_UUID);
        self::assertSame('indeterminate', $blocker['remote_status']);
        self::assertNotNull($blocker['active_scope_key'], 'a possibly-charged attempt must stay locked');

        $sessions = array_filter(
            $this->apiCalls,
            static fn (array $call): bool => 'helcim-pay/initialize' === $call['endpoint']
        );
        self::assertCount(1, $sessions, 'no second checkout session may be created');
    }

    /** Exact late approval proof must still bind a released (canceled) checkout. */
    public function testACanceledReleasedCheckoutStillAcceptsExactLateApprovalProof(): void
    {
        self::assertIsArray($this->processor->initialize($this->paymentInstance()));
        self::assertTrue($this->repository->transitionRemote(self::OPERATION_UUID, 'processing', 'indeterminate'));
        self::assertTrue(
            $this->repository->transitionRemote(
                self::OPERATION_UUID,
                'indeterminate',
                'canceled',
                ['error_code' => 'ys_helcim_session_expired_released']
            )
        );

        self::assertTrue(
            $this->repository->transitionRemote(
                self::OPERATION_UUID,
                'canceled',
                'succeeded',
                ['vendor_transaction_id' => '51999001']
            ),
            'canceled -> succeeded must be a legal transition for late proof'
        );

        $row = $this->repository->findByUuid(self::OPERATION_UUID);
        self::assertSame('succeeded', $row['remote_status']);
        self::assertSame('51999001', (string) $row['vendor_transaction_id']);
    }

    /**
     * Processor whose provider double distinguishes the release lookup from the session
     * initialize, so the blocked-scope path can be exercised end to end.
     *
     * @param array<int,array<string,mixed>> $lookupResponse Provider list returned for card-transactions.
     */
    /**
     * The decisive resumed-payment chain: FluentCart wipes the transaction meta on
     * retry, the resume rebuilds it from the operation-bound envelope, and the
     * browser then confirms an exact approval with the ROTATED token — ending in an
     * immediate receipt and applied local state, exactly like a fresh payment.
     */
    public function testResumeRebuildsBrowserSessionMetaAndTheRotatedTokenConfirms(): void
    {
        self::assertIsArray($this->processor->initialize($this->paymentInstance()));

        // FluentCart checkout retry wipes the transaction meta.
        OrderTransaction::query()->where('id', 20)->first()->fill(['meta' => []])->save();
        self::assertSame([], OrderTransaction::allRecords()[20]['meta']);

        $second = $this->scopeAwareProcessor(self::SECOND_OPERATION_UUID);
        $resumed = $second->initialize($this->paymentInstance());
        self::assertIsArray($resumed);
        self::assertSame(self::OPERATION_UUID, $resumed['payment_data']['operation_uuid']);

        $meta = OrderTransaction::allRecords()[20]['meta'];
        self::assertSame(self::OPERATION_UUID, $meta['ys_helcim_operation_uuid'] ?? null, 'the confirmation correlation meta must be rebuilt');
        self::assertSame('hosted-checkout-token-741', $meta['ys_helcim_checkout_token'] ?? null);
        self::assertNotSame('', (string) ($meta['ys_helcim_secret_token_enc'] ?? ''), 'the provider secret must be rebuilt for hash validation');

        $event = $this->approvedEvent(self::OPERATION_UUID);
        $_POST = [
            'transaction_uuid' => $resumed['payment_data']['transaction_uuid'],
            'operation_uuid' => $resumed['payment_data']['operation_uuid'],
            'confirm_token' => $resumed['payment_data']['confirm_token'],
            'nonce' => $resumed['payment_data']['confirm_nonce'],
            'event_data' => json_encode($event),
            'hash' => $this->hash($event),
        ];

        try {
            $second->handleConfirmAjax();
            self::fail('Expected wp_send_json to terminate the request.');
        } catch (\YSHelcimWpJsonExit $response) {
            self::assertSame(200, $response->statusCode, 'a resumed payment must confirm exactly like a fresh one');
            self::assertSame('success', $response->payload['status']);
        }

        self::assertSame('51177991', OrderTransaction::allRecords()[20]['vendor_charge_id']);
        self::assertSame('paid', Order::allRecords()[10]['payment_status']);
        $row = $this->repository->findByUuid(self::OPERATION_UUID);
        self::assertSame('succeeded', $row['remote_status']);
        self::assertSame('applied', $row['local_status']);
    }

    /** Resume material copied from another operation must be refused untouched (anti-swap). */
    public function testResumeRefusesMaterialSwappedFromAnotherOperation(): void
    {
        self::assertIsArray($this->processor->initialize($this->paymentInstance()));

        $foreign = Helper::encryptKey(json_encode([
            'v' => 2,
            'operation_uuid' => self::SECOND_OPERATION_UUID,
            'checkout_token' => 'stolen-checkout-token',
            'secret_token' => 'stolen-secret-token',
        ]));
        self::assertIsString($foreign);
        self::assertTrue($this->repository->storeCheckoutMaterial(self::OPERATION_UUID, $foreign, '2026-07-22 01:10:00'));

        OrderTransaction::query()->where('id', 20)->first()->fill(['meta' => []])->save();

        $second = $this->scopeAwareProcessor(self::SECOND_OPERATION_UUID);
        $result = $second->initialize($this->paymentInstance());

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_previous_attempt_unresolved', $result->get_error_code());

        $blocker = $this->repository->findByUuid(self::OPERATION_UUID);
        self::assertSame('processing', $blocker['remote_status'], 'a swapped envelope must change nothing');
        self::assertSame(
            hash('sha256', self::CONFIRM_TOKEN),
            (string) $blocker['confirm_token_hash'],
            'the confirm token must NOT rotate on a refused resume'
        );
        self::assertSame([], OrderTransaction::allRecords()[20]['meta'], 'no meta may be rebuilt from foreign material');
    }

    /**
     * P1: a resumed session is already exposed at Helcim, so a meta-restore failure
     * must keep it processing + LOCKED and report unresolved — never fail/release it
     * the way a never-exposed fresh session would.
     */
    public function testResumeRestoreFailureKeepsTheLiveSessionLockedAndProcessing(): void
    {
        self::assertIsArray($this->processor->initialize($this->paymentInstance()));

        // Storage breaks between the attempts (save failures on the transaction).
        OrderTransaction::$saveResult = false;

        $second = $this->scopeAwareProcessor(self::SECOND_OPERATION_UUID);
        $result = $second->initialize($this->paymentInstance());

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_previous_attempt_unresolved', $result->get_error_code());

        $blocker = $this->repository->findByUuid(self::OPERATION_UUID);
        self::assertSame('processing', $blocker['remote_status'], 'an exposed session must NEVER be failed by a restore error');
        self::assertNotNull($blocker['active_scope_key'], 'the scope must stay locked');
        self::assertSame(
            hash('sha256', self::CONFIRM_TOKEN),
            (string) $blocker['confirm_token_hash'],
            'the confirm token must not rotate when the restore failed'
        );
    }

    /** A malformed envelope (arrays where strings belong) is refused, never coerced. */
    public function testResumeRefusesAMalformedEnvelopeWithNonStringFields(): void
    {
        self::assertIsArray($this->processor->initialize($this->paymentInstance()));

        $malformed = Helper::encryptKey(json_encode([
            'v' => 2,
            'operation_uuid' => self::OPERATION_UUID,
            'checkout_token' => ['nested' => 'array'],
            'secret_token' => 12345,
        ]));
        self::assertIsString($malformed);
        self::assertTrue($this->repository->storeCheckoutMaterial(self::OPERATION_UUID, $malformed, '2026-07-22 01:10:00'));

        $second = $this->scopeAwareProcessor(self::SECOND_OPERATION_UUID);
        $result = $second->initialize($this->paymentInstance());

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_previous_attempt_unresolved', $result->get_error_code());
        self::assertSame('processing', $this->repository->findByUuid(self::OPERATION_UUID)['remote_status']);
    }

    /** The rotation CAS runs against the caller's snapshot: a stale snapshot loses. */
    public function testConfirmTokenRotationIsACompareAndSwapOnTheCallerSnapshot(): void
    {
        self::assertIsArray($this->processor->initialize($this->paymentInstance()));
        $snapshot = hash('sha256', self::CONFIRM_TOKEN);

        $first = $this->repository->rotateConfirmToken(
            self::OPERATION_UUID,
            hash('sha256', 'winner-token'),
            '2026-07-22 00:20:00',
            $snapshot
        );
        self::assertTrue($first);

        $second = $this->repository->rotateConfirmToken(
            self::OPERATION_UUID,
            hash('sha256', 'loser-token'),
            '2026-07-22 00:20:00',
            $snapshot
        );
        self::assertFalse($second, 'the same stale snapshot must lose the race by construction');
        self::assertSame(
            hash('sha256', 'winner-token'),
            (string) $this->repository->findByUuid(self::OPERATION_UUID)['confirm_token_hash']
        );
    }

    /** Released and mismatch-failed operations must surface in the attention scan. */
    public function testAttentionScanSurfacesReleasedAndMismatchFailedOperations(): void
    {
        self::assertIsArray($this->processor->initialize($this->paymentInstance()));

        // Released checkout: canceled / pending / scope NULL.
        self::assertTrue($this->repository->transitionRemote(self::OPERATION_UUID, 'processing', 'indeterminate'));
        self::assertTrue(
            $this->repository->transitionRemote(
                self::OPERATION_UUID,
                'indeterminate',
                'canceled',
                ['error_code' => 'ys_helcim_session_expired_released']
            )
        );

        $rows = $this->repository->findPurchasesNeedingAttention('ys_helcim', 10, 7);
        self::assertIsArray($rows);
        $uuids = array_map(static fn (array $r): string => (string) $r['operation_uuid'], $rows);
        self::assertContains(self::OPERATION_UUID, $uuids, 'a released checkout must be administrator-visible');

        // Late-proof bound remotely, locally mismatch-failed: succeeded / failed / scope NULL.
        self::assertTrue(
            $this->repository->transitionRemote(
                self::OPERATION_UUID,
                'canceled',
                'succeeded',
                ['vendor_transaction_id' => '51999002']
            )
        );
        self::assertTrue(
            $this->repository->recordLocalFailure(
                self::OPERATION_UUID,
                'provider_id_mismatch',
                'A different provider transaction is already bound.'
            )
        );

        $rows = $this->repository->findPurchasesNeedingAttention('ys_helcim', 10, 7);
        self::assertIsArray($rows);
        $uuids = array_map(static fn (array $r): string => (string) $r['operation_uuid'], $rows);
        self::assertContains(self::OPERATION_UUID, $uuids, 'a provider-paid but locally unbindable charge must be administrator-visible');
    }

    /**
     * Deterministic behavior at the resume/expiry boundaries.
     *
     * @dataProvider blockerAgeBoundaries
     */
    public function testBlockedScopeBehaviorAtTheExactTimeBoundaries(int $age, string $expectation): void
    {
        self::assertIsArray($this->processor->initialize($this->paymentInstance()));

        $second = $this->scopeAwareProcessor(self::SECOND_OPERATION_UUID, [], self::clockAtAge($age));
        $result = $second->initialize($this->paymentInstance());

        if ('resume' === $expectation) {
            self::assertIsArray($result);
            self::assertSame(self::OPERATION_UUID, $result['payment_data']['operation_uuid']);
            return;
        }
        if ('cooling' === $expectation) {
            self::assertInstanceOf(\WP_Error::class, $result);
            self::assertSame('ys_helcim_previous_attempt_cooling', $result->get_error_code());
            self::assertSame('processing', $this->repository->findByUuid(self::OPERATION_UUID)['remote_status']);
            return;
        }

        self::assertIsArray($result, 'at the expiry boundary the blocker releases and a fresh session opens');
        self::assertSame(self::SECOND_OPERATION_UUID, $result['payment_data']['operation_uuid']);
        self::assertSame('canceled', $this->repository->findByUuid(self::OPERATION_UUID)['remote_status']);
    }

    /** @return array<string, array{0:int,1:string}> */
    public static function blockerAgeBoundaries(): array
    {
        return [
            'one second inside the resume window (54:59)' => [3299, 'resume'],
            'exactly at the resume boundary (55:00)' => [3300, 'cooling'],
            'one second before expiry (69:59)' => [4199, 'cooling'],
            'exactly at the expiry boundary (70:00)' => [4200, 'released'],
        ];
    }

    private function scopeAwareProcessor(
        string $operationUuid,
        array $lookupResponse = [],
        ?int $nowEpoch = null
    ): YSHelcimPayProcessor {
        $now = $nowEpoch ?? strtotime('2026-07-22 00:05:00 UTC');

        return new YSHelcimPayProcessor(
            new YSHelcimPaySettings(),
            operations: $this->repository,
            api_request: function (
                string $endpoint,
                array $payload,
                string $apiToken,
                ?string $idempotencyKey = null,
                string $method = 'POST'
            ) use ($lookupResponse) {
                $this->apiCalls[] = compact('endpoint', 'payload', 'apiToken', 'idempotencyKey', 'method');
                if ('card-transactions' === $endpoint) {
                    return $lookupResponse;
                }
                return [
                    'checkoutToken' => 'hosted-checkout-token-742',
                    'secretToken' => self::SECRET_TOKEN,
                ];
            },
            uuid_factory: static fn (): string => $operationUuid,
            confirm_token_factory: static fn (): string => self::SECOND_CONFIRM_TOKEN,
            initialization_clock: static fn (): int => $now
        );
    }

    /** Blockers are created at 2026-07-22 00:00:00; this maps an age to a clock value. */
    private static function clockAtAge(int $ageSeconds): int
    {
        return strtotime('2026-07-22 00:00:00 UTC') + $ageSeconds;
    }

    /** Rebuild the processor so it picks up settings changed inside a test. */
    private function processorWithCurrentSettings(): YSHelcimPayProcessor
    {
        return new YSHelcimPayProcessor(
            new YSHelcimPaySettings(),
            operations: $this->repository,
            api_request: function (
                string $endpoint,
                array $payload,
                string $apiToken,
                ?string $idempotencyKey = null,
                string $method = 'POST'
            ): array {
                $row = $this->repository->findByUuid(self::OPERATION_UUID);
                $this->apiCalls[] = compact('endpoint', 'payload', 'apiToken', 'idempotencyKey', 'method', 'row');
                return [
                    'checkoutToken' => 'hosted-checkout-token-741',
                    'secretToken' => self::SECRET_TOKEN,
                ];
            },
            uuid_factory: static fn (): string => self::OPERATION_UUID,
            confirm_token_factory: static fn (): string => self::CONFIRM_TOKEN,
            initialization_clock: static fn (): int => strtotime('2026-07-22 00:00:00 UTC')
        );
    }

    public function testConfirmAjaxRejectsAnInvalidNonceBeforeConsumingTheOneTimeToken(): void
    {
        $paymentData = $this->initializePaymentData();
        $event = $this->approvedEvent($paymentData['operation_uuid']);
        $_POST = [
            'transaction_uuid' => $paymentData['transaction_uuid'],
            'operation_uuid' => $paymentData['operation_uuid'],
            'confirm_token' => $paymentData['confirm_token'],
            'nonce' => 'wrong-nonce',
            'event_data' => json_encode($event),
            'hash' => $this->hash($event),
        ];

        try {
            $this->processor->handleConfirmAjax();
            self::fail('Expected wp_send_json to terminate the request.');
        } catch (\YSHelcimWpJsonExit $response) {
            self::assertSame(403, $response->statusCode);
            self::assertSame('failed', $response->payload['status']);
        }

        self::assertTrue($this->repository->consumeConfirmToken(self::OPERATION_UUID, self::CONFIRM_TOKEN));
        self::assertSame(Status::TRANSACTION_PENDING, OrderTransaction::allRecords()[20]['status']);
    }

    public function testConfirmAjaxRoutesExactProofThroughTheJournalAndReturnsTheReceipt(): void
    {
        $paymentData = $this->initializePaymentData();
        $event = $this->approvedEvent($paymentData['operation_uuid']);
        $_POST = [
            'transaction_uuid' => $paymentData['transaction_uuid'],
            'operation_uuid' => $paymentData['operation_uuid'],
            'confirm_token' => $paymentData['confirm_token'],
            'nonce' => $paymentData['confirm_nonce'],
            'event_data' => json_encode($event),
            'hash' => $this->hash($event),
        ];

        try {
            $this->processor->handleConfirmAjax();
            self::fail('Expected wp_send_json to terminate the request.');
        } catch (\YSHelcimWpJsonExit $response) {
            self::assertSame(200, $response->statusCode);
            self::assertSame('success', $response->payload['status']);
            self::assertSame('https://shop.test/receipt/fc-hosted-transaction-741', $response->payload['redirect_url']);
            self::assertSame('order-uuid-10', $response->payload['order']['uuid']);
        }

        self::assertSame('51177991', OrderTransaction::allRecords()[20]['vendor_charge_id']);
        self::assertSame('paid', Order::allRecords()[10]['payment_status']);
        self::assertCount(1, StatusHelper::$syncs);
        $row = $this->repository->findByUuid(self::OPERATION_UUID);
        self::assertSame('succeeded', $row['remote_status']);
        self::assertSame('applied', $row['local_status']);
    }

    #[DataProvider('arrayShapedPostFields')]
    public function testConfirmAjaxRejectsArrayShapedPostFieldsWithoutTypeErrors(string $field, int $expectedStatus): void
    {
        $paymentData = $this->initializePaymentData();
        $event = $this->approvedEvent($paymentData['operation_uuid']);
        $_POST = [
            'transaction_uuid' => $paymentData['transaction_uuid'],
            'operation_uuid' => $paymentData['operation_uuid'],
            'confirm_token' => $paymentData['confirm_token'],
            'nonce' => $paymentData['confirm_nonce'],
            'event_data' => json_encode($event),
            'hash' => $this->hash($event),
        ];
        $_POST[$field] = ['malformed'];

        try {
            $this->processor->handleConfirmAjax();
            self::fail('Expected wp_send_json to terminate the request.');
        } catch (\YSHelcimWpJsonExit $response) {
            self::assertSame($expectedStatus, $response->statusCode);
            self::assertSame('failed', $response->payload['status']);
            self::assertFalse($response->payload['retry_allowed']);
        }
    }

    public static function arrayShapedPostFields(): iterable
    {
        yield 'nonce' => ['nonce', 403];
        yield 'transaction UUID' => ['transaction_uuid', 400];
        yield 'operation UUID' => ['operation_uuid', 422];
        yield 'confirm token' => ['confirm_token', 422];
        yield 'hash' => ['hash', 422];
        yield 'event data' => ['event_data', 400];
    }

    public function testSuccessReceiptFailsClosedWhenExactOrderUuidIsMissing(): void
    {
        $transaction = OrderTransaction::query()->where('id', 20)->first();
        self::assertInstanceOf(OrderTransaction::class, $transaction);
        Order::reset();

        $method = new \ReflectionMethod(YSHelcimPayProcessor::class, 'buildSuccessResponse');
        $result = $method->invoke($this->processor, $transaction);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_confirm_receipt_missing', $result->get_error_code());
    }

    #[DataProvider('confirmationHttpStatuses')]
    public function testConfirmationErrorsUseExplicitFailClosedHttpAllowlist(string $code, int $expected): void
    {
        $method = new \ReflectionMethod(YSHelcimPayProcessor::class, 'confirmationHttpStatus');

        self::assertSame($expected, $method->invoke(null, new \WP_Error($code, 'test')));
    }

    public static function confirmationHttpStatuses(): iterable
    {
        yield 'malformed transaction' => ['ys_helcim_confirm_transaction_invalid', 400];
        yield 'missing transaction' => ['ys_helcim_confirm_transaction_missing', 404];
        yield 'hash mismatch' => ['ys_helcim_confirm_hash_invalid', 422];
        yield 'correlation mismatch' => ['ys_helcim_confirm_correlation_invalid', 422];
        yield 'proof mismatch' => ['ys_helcim_confirm_proof_invalid', 422];
        yield 'one time token mismatch' => ['ys_helcim_confirm_token_invalid', 422];
        yield 'operation conflict' => ['ys_helcim_operation_conflict', 409];
        yield 'unsafe paid state' => ['ys_helcim_local_purchase_already_succeeded', 409];
        yield 'journal unavailable' => ['ys_helcim_journal_unavailable', 503];
        yield 'local save unverified' => ['ys_helcim_purchase_save_unverified', 503];
        yield 'unknown code fails closed' => ['unknown_new_error', 503];
    }

    /** @return array<string,mixed> */
    private function initializePaymentData(): array
    {
        $result = $this->processor->initialize($this->paymentInstance());
        self::assertIsArray($result);
        return $result['payment_data'];
    }

    private function paymentInstance(): PaymentInstance
    {
        return new PaymentInstance(
            Order::query()->where('id', 10)->first(),
            OrderTransaction::query()->where('id', 20)->first()
        );
    }

    private function processorWithApi(callable $apiRequest): YSHelcimPayProcessor
    {
        return new YSHelcimPayProcessor(
            new YSHelcimPaySettings(),
            operations: $this->repository,
            api_request: $apiRequest,
            uuid_factory: static fn (): string => self::OPERATION_UUID,
            confirm_token_factory: static fn (): string => self::CONFIRM_TOKEN,
            initialization_clock: static fn (): int => strtotime('2026-07-22 00:00:00 UTC')
        );
    }

    /** @return array<string,mixed> */
    private function approvedEvent(string $correlation): array
    {
        return [
            'status' => 'APPROVED',
            'type' => 'purchase',
            'transactionId' => '51177991',
            'amount' => '21.00',
            'currency' => 'USD',
            'invoiceNumber' => $correlation,
            'cardNumber' => '************9990',
            'cardType' => 'VI',
        ];
    }

    /** @param array<string,mixed> $event */
    private function hash(array $event): string
    {
        return hash('sha256', json_encode($event) . self::SECRET_TOKEN);
    }

    private function seedModels(): void
    {
        OrderTransaction::seed([
            'id' => 20,
            'uuid' => 'fc-hosted-transaction-741',
            'order_id' => 10,
            'payment_method' => 'ys_helcim',
            'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
            'total' => 2100,
            'currency' => 'USD',
            'payment_mode' => 'test',
            'status' => Status::TRANSACTION_PENDING,
            'vendor_charge_id' => null,
            'payment_method_type' => '',
            'card_last_4' => '',
            'card_brand' => '',
            'meta' => ['existing' => 'kept'],
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
}
