<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\HelcimPay;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\HelcimPay\YSHelcimPayInitializationCoordinator;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationRepository;
use YangSheep\Helcim\FluentCart\Support\YSHelcimApiClient;
use YangSheep\Helcim\FluentCart\Tests\Doubles\FakeWpdb;

final class YSHelcimPayInitializationCoordinatorTest extends TestCase
{
    private const OPERATION_UUID = '00000000-0000-4000-8000-000000000451';

    private const CONFIRM_TOKEN = 'abcdefghijklmnopqrstuvwxyzABCDEFGH_12345678';

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

    public function testItPersistsAndClaimsTheOperationBeforeInitializing(): void
    {
        $initializeCalls = [];
        $coordinator = $this->coordinator(
            function (array $identity, string $correlation) use (&$initializeCalls): array {
                $initializeCalls[] = [
                    'identity' => $identity,
                    'correlation' => $correlation,
                    'rows' => $this->database->allRows(),
                ];

                return [
                    'checkoutToken' => 'checkout-token',
                    'secretToken' => 'secret-token',
                ];
            }
        );

        $result = $coordinator->begin($this->transaction());

        self::assertSame(
            ['operation_uuid', 'confirm_token', 'checkout_token', 'secret_token'],
            array_keys($result)
        );
        self::assertSame(self::OPERATION_UUID, $result['operation_uuid']);
        self::assertSame(self::CONFIRM_TOKEN, $result['confirm_token']);
        self::assertSame('checkout-token', $result['checkout_token']);
        self::assertSame('secret-token', $result['secret_token']);
        self::assertCount(1, $initializeCalls);
        self::assertSame($this->transaction(), $initializeCalls[0]['identity']);
        self::assertSame(self::OPERATION_UUID, $initializeCalls[0]['correlation']);
        self::assertCount(1, $initializeCalls[0]['rows']);
        self::assertSame('processing', $initializeCalls[0]['rows'][0]['remote_status']);

        $stored = $this->repository->findByUuid(self::OPERATION_UUID);
        self::assertSame(self::OPERATION_UUID, $stored['provider_correlation_id']);
        self::assertSame(hash('sha256', self::CONFIRM_TOKEN), $stored['confirm_token_hash']);
        self::assertSame('2026-07-21 00:15:00', $stored['confirm_token_expires_at']);
        self::assertSame('processing', $stored['remote_status']);
        self::assertStringNotContainsString(
            self::CONFIRM_TOKEN,
            json_encode($this->database->allRows(), JSON_THROW_ON_ERROR)
        );
        self::assertStringNotContainsString(
            'secret-token',
            json_encode($this->database->allRows(), JSON_THROW_ON_ERROR)
        );
    }

    public function testProviderInitializationErrorBecomesLockedIndeterminateBeforeReturning(): void
    {
        $initializeCalls = 0;
        $coordinator = $this->coordinator(
            static function () use (&$initializeCalls): \WP_Error {
                ++$initializeCalls;
                return new \WP_Error('transport_error', 'Connection timed out.');
            }
        );

        $result = $coordinator->begin($this->transaction());

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_initialize_indeterminate', $result->get_error_code());
        self::assertSame(1, $initializeCalls);
        $stored = $this->repository->findByUuid(self::OPERATION_UUID);
        self::assertSame('indeterminate', $stored['remote_status']);
        self::assertSame($stored['scope_key'], $stored['active_scope_key']);
        self::assertSame('helcim_pay_initialize_unresolved', $stored['remote_error_code']);
        self::assertNull($stored['vendor_transaction_id']);
    }

    public function testExactLocalNeverSentInitializationErrorFailsAndReleasesTheScope(): void
    {
        $providerError = new \WP_Error(
            'ys_helcim_api_error',
            'The provider request was rejected locally.',
            [
                'kind' => 'local',
                'indeterminate' => false,
                'mutation_disposition' => YSHelcimApiClient::MUTATION_NEVER_SENT,
            ]
        );
        $coordinator = $this->coordinator(static fn (): \WP_Error => $providerError);

        $result = $coordinator->begin($this->transaction());

        self::assertSame($providerError, $result);
        $stored = $this->repository->findByUuid(self::OPERATION_UUID);
        self::assertSame('failed', $stored['remote_status']);
        self::assertNull($stored['active_scope_key']);
        self::assertSame('helcim_pay_initialize_never_sent', $stored['remote_error_code']);
        self::assertNull($stored['vendor_transaction_id']);
    }

    #[DataProvider('unsafeInitializationErrors')]
    public function testNonExactInitializationErrorsRemainLockedIndeterminate(array $errorData): void
    {
        $coordinator = $this->coordinator(
            static fn (): \WP_Error => new \WP_Error(
                'ys_helcim_api_error',
                'Initialization failed.',
                $errorData
            )
        );

        $result = $coordinator->begin($this->transaction());

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_initialize_indeterminate', $result->get_error_code());
        $stored = $this->repository->findByUuid(self::OPERATION_UUID);
        self::assertSame('indeterminate', $stored['remote_status']);
        self::assertSame($stored['scope_key'], $stored['active_scope_key']);
    }

    public static function unsafeInitializationErrors(): iterable
    {
        yield 'missing local kind' => [[
            'indeterminate' => false,
            'mutation_disposition' => YSHelcimApiClient::MUTATION_NEVER_SENT,
        ]];
        yield 'transport kind' => [[
            'kind' => 'transport',
            'indeterminate' => false,
            'mutation_disposition' => YSHelcimApiClient::MUTATION_NEVER_SENT,
        ]];
        yield 'missing explicit indeterminate flag' => [[
            'kind' => 'local',
            'mutation_disposition' => YSHelcimApiClient::MUTATION_NEVER_SENT,
        ]];
        yield 'indeterminate outcome' => [[
            'kind' => 'local',
            'indeterminate' => true,
            'mutation_disposition' => YSHelcimApiClient::MUTATION_NEVER_SENT,
        ]];
        yield 'authentication rejection is not a local never sent proof' => [[
            'kind' => 'provider',
            'http_code' => 401,
            'indeterminate' => false,
            'mutation_disposition' => YSHelcimApiClient::MUTATION_AUTHENTICATION_REJECTED,
        ]];
    }

    public function testDatabaseInsertFailureNeverInitializes(): void
    {
        $initializeCalls = 0;
        $this->database->failNextInsert = true;
        $coordinator = $this->coordinator(
            static function () use (&$initializeCalls): array {
                ++$initializeCalls;
                return ['checkoutToken' => 'checkout-token', 'secretToken' => 'secret-token'];
            }
        );

        $result = $coordinator->begin($this->transaction());

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_journal_unavailable', $result->get_error_code());
        self::assertSame(0, $initializeCalls);
        self::assertSame([], $this->database->allRows());
    }

    public function testDatabaseCreateReadbackFailureNeverInitializes(): void
    {
        $initializeCalls = 0;
        $this->database->failNextLookup = true;
        $coordinator = $this->coordinator(
            static function () use (&$initializeCalls): array {
                ++$initializeCalls;
                return ['checkoutToken' => 'checkout-token', 'secretToken' => 'secret-token'];
            }
        );

        $result = $coordinator->begin($this->transaction());

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_journal_unavailable', $result->get_error_code());
        self::assertSame(0, $initializeCalls);
        self::assertSame('created', $this->database->allRows()[0]['remote_status']);
    }

    public function testDatabaseClaimFailureNeverInitializes(): void
    {
        $initializeCalls = 0;
        $this->database->failNextUpdate = true;
        $coordinator = $this->coordinator(
            static function () use (&$initializeCalls): array {
                ++$initializeCalls;
                return ['checkoutToken' => 'checkout-token', 'secretToken' => 'secret-token'];
            }
        );

        $result = $coordinator->begin($this->transaction());

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_journal_unavailable', $result->get_error_code());
        self::assertSame(0, $initializeCalls);
        self::assertSame('created', $this->repository->findByUuid(self::OPERATION_UUID)['remote_status']);
    }

    public function testDatabasePostClaimReadbackFailureNeverInitializes(): void
    {
        $clockCalls = 0;
        $this->repository = new YSHelcimOperationRepository(
            $this->database,
            function () use (&$clockCalls): string {
                ++$clockCalls;
                if (4 === $clockCalls) {
                    $this->database->failNextLookup = true;
                }
                return '2026-07-21 00:00:00';
            }
        );
        $initializeCalls = 0;
        $coordinator = $this->coordinator(
            static function () use (&$initializeCalls): array {
                ++$initializeCalls;
                return ['checkoutToken' => 'checkout-token', 'secretToken' => 'secret-token'];
            }
        );

        $result = $coordinator->begin($this->transaction());

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_journal_unavailable', $result->get_error_code());
        self::assertSame(0, $initializeCalls);
        self::assertSame('processing', $this->database->allRows()[0]['remote_status']);
    }

    public function testActiveScopePreventsAConcurrentSecondInitialization(): void
    {
        $initializeCalls = 0;
        $nestedResult = null;
        $coordinator = null;
        $coordinator = $this->coordinator(
            function () use (&$initializeCalls, &$nestedResult, &$coordinator): array {
                ++$initializeCalls;
                $nestedResult = $coordinator->begin($this->transaction());
                return ['checkoutToken' => 'checkout-token', 'secretToken' => 'secret-token'];
            }
        );

        $firstResult = $coordinator->begin($this->transaction());

        self::assertIsArray($firstResult);
        self::assertInstanceOf(\WP_Error::class, $nestedResult);
        self::assertSame('ys_helcim_scope_busy', $nestedResult->get_error_code());
        self::assertSame(1, $initializeCalls);
        self::assertCount(1, $this->database->allRows());
    }

    #[DataProvider('invalidProviderResponses')]
    public function testNonExactProviderResponseFailsClosed(mixed $providerResponse): void
    {
        $coordinator = $this->coordinator(static fn (): mixed => $providerResponse);

        $result = $coordinator->begin($this->transaction());

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_initialize_indeterminate', $result->get_error_code());
        $stored = $this->repository->findByUuid(self::OPERATION_UUID);
        self::assertSame('indeterminate', $stored['remote_status']);
        self::assertSame($stored['scope_key'], $stored['active_scope_key']);
    }

    public static function invalidProviderResponses(): iterable
    {
        yield 'missing secret token' => [[
            'checkoutToken' => 'checkout-token',
        ]];
        yield 'unexpected field' => [[
            'checkoutToken' => 'checkout-token',
            'secretToken' => 'secret-token',
            'unexpected' => 'value',
        ]];
        yield 'empty checkout token' => [[
            'checkoutToken' => '',
            'secretToken' => 'secret-token',
        ]];
        yield 'whitespace checkout token' => [[
            'checkoutToken' => '   ',
            'secretToken' => 'secret-token',
        ]];
        yield 'whitespace secret token' => [[
            'checkoutToken' => 'checkout-token',
            'secretToken' => " \t ",
        ]];
        yield 'non-string secret token' => [[
            'checkoutToken' => 'checkout-token',
            'secretToken' => 123,
        ]];
    }

    public function testThrownInitializationFailureBecomesLockedIndeterminate(): void
    {
        $coordinator = $this->coordinator(
            static function (): never {
                throw new \RuntimeException('timeout containing secret data');
            }
        );

        $result = $coordinator->begin($this->transaction());

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_initialize_indeterminate', $result->get_error_code());
        $stored = $this->repository->findByUuid(self::OPERATION_UUID);
        self::assertSame('indeterminate', $stored['remote_status']);
        self::assertSame($stored['scope_key'], $stored['active_scope_key']);
        self::assertStringNotContainsString(
            'secret data',
            json_encode($this->database->allRows(), JSON_THROW_ON_ERROR)
        );
    }

    public function testFailedInitializationTransitionFailureReturnsNoTokens(): void
    {
        $coordinator = $this->coordinator(
            function (): \WP_Error {
                $this->database->failNextUpdate = true;
                return new \WP_Error('transport_error', 'Connection timed out.');
            }
        );

        $result = $coordinator->begin($this->transaction());

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_journal_outcome_unpersisted', $result->get_error_code());
        self::assertSame('processing', $this->repository->findByUuid(self::OPERATION_UUID)['remote_status']);
    }

    public function testInitializationFailureReadbackLossNeverUnlocksAnUnprovenOperation(): void
    {
        $clockCalls = 0;
        $this->repository = new YSHelcimOperationRepository(
            $this->database,
            function () use (&$clockCalls): string {
                ++$clockCalls;
                if (5 === $clockCalls) {
                    $this->database->failNextLookup = true;
                }
                return '2026-07-21 00:00:00';
            }
        );
        $coordinator = $this->coordinator(
            static fn (): \WP_Error => new \WP_Error('transport_error', 'Connection timed out.')
        );

        $result = $coordinator->begin($this->transaction());

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_initialize_indeterminate', $result->get_error_code());
        $stored = $this->database->allRows()[0];
        self::assertSame('indeterminate', $stored['remote_status']);
        self::assertSame($stored['scope_key'], $stored['active_scope_key']);
        self::assertNotNull($stored['active_scope_key']);
    }

    public function testCorruptedConfirmTokenExpiryPreventsInitialization(): void
    {
        $clockCalls = 0;
        $this->repository = new YSHelcimOperationRepository(
            $this->database,
            function () use (&$clockCalls): string {
                ++$clockCalls;
                if (4 === $clockCalls) {
                    $this->database->update(
                        'ignored',
                        ['confirm_token_expires_at' => '2026-07-21 00:14:59'],
                        ['operation_uuid' => self::OPERATION_UUID]
                    );
                }
                return '2026-07-21 00:00:00';
            }
        );
        $initializeCalls = 0;
        $coordinator = $this->coordinator(
            static function () use (&$initializeCalls): array {
                ++$initializeCalls;
                return ['checkoutToken' => 'checkout-token', 'secretToken' => 'secret-token'];
            }
        );

        $result = $coordinator->begin($this->transaction());

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_operation_conflict', $result->get_error_code());
        self::assertSame(0, $initializeCalls);
        self::assertSame(
            '2026-07-21 00:14:59',
            $this->database->allRows()[0]['confirm_token_expires_at']
        );
    }

    public function testReleasedActiveScopePreventsInitialization(): void
    {
        $clockCalls = 0;
        $this->repository = new YSHelcimOperationRepository(
            $this->database,
            function () use (&$clockCalls): string {
                ++$clockCalls;
                if (4 === $clockCalls) {
                    $this->database->update(
                        'ignored',
                        ['active_scope_key' => null],
                        ['operation_uuid' => self::OPERATION_UUID]
                    );
                }
                return '2026-07-21 00:00:00';
            }
        );
        $initializeCalls = 0;
        $coordinator = $this->coordinator(
            static function () use (&$initializeCalls): array {
                ++$initializeCalls;
                return ['checkoutToken' => 'checkout-token', 'secretToken' => 'secret-token'];
            }
        );

        $result = $coordinator->begin($this->transaction());

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_operation_conflict', $result->get_error_code());
        self::assertSame(0, $initializeCalls);
        self::assertNull($this->database->allRows()[0]['active_scope_key']);
    }

    public function testNonHostedIdentityNeverCreatesOrInitializes(): void
    {
        $initializeCalls = 0;
        $transaction = $this->transaction();
        $transaction['gateway'] = 'ys_helcim_js';
        $coordinator = $this->coordinator(
            static function () use (&$initializeCalls): array {
                ++$initializeCalls;
                return ['checkoutToken' => 'checkout-token', 'secretToken' => 'secret-token'];
            }
        );

        $result = $coordinator->begin($transaction);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_invalid_operation', $result->get_error_code());
        self::assertSame(0, $initializeCalls);
        self::assertSame([], $this->database->allRows());
    }

    private function coordinator(callable $initialize): YSHelcimPayInitializationCoordinator
    {
        return new YSHelcimPayInitializationCoordinator(
            $this->repository,
            $initialize,
            static fn (): string => self::OPERATION_UUID,
            static fn (): string => self::CONFIRM_TOKEN,
            static fn (): int => 1784592000
        );
    }

    /** @return array<string, int|string> */
    private function transaction(): array
    {
        return [
            'gateway' => 'ys_helcim',
            'order_id' => 101,
            'transaction_id' => 202,
            'transaction_uuid' => 'fc-hosted-transaction-202',
            'amount' => 2100,
            'currency' => 'USD',
            'payment_mode' => 'test',
        ];
    }
}
