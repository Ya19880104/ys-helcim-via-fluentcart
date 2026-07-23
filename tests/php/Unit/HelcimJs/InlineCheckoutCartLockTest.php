<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\HelcimJs;

use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\HelcimJs\YSHelcimInlineCheckoutCartLock;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimPurchaseOperation;

final class InlineCheckoutCartLockTest extends TestCase
{
    public function testFreshReadRejectsAConcurrentOrderCreatedFromTheSameStaleCartSnapshot(): void
    {
        if (!class_exists(YSHelcimInlineCheckoutCartLock::class)) {
            self::fail('The inline checkout cart lock guard is not implemented.');
        }

        $database = new InlineCheckoutCartLockWpdb();
        $database->lockResults = ['1'];
        $database->cartRows = [[
            'order_id' => '481',
            'stage' => 'intended',
        ]];
        $shutdownCallbacks = [];
        $staleCart = (object) [
            'cart_hash' => 'raw-cart-hash-that-must-not-be-a-lock-name',
            'order_id' => null,
            'stage' => 'draft',
        ];
        $guard = new YSHelcimInlineCheckoutCartLock(
            $database,
            static fn (): object => $staleCart,
            static function (callable $callback) use (&$shutdownCallbacks): void {
                $shutdownCallbacks[] = $callback;
            }
        );

        $result = $guard->validate(true, ['_fct_pay_method' => 'ys_helcim_js']);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_checkout_already_started', $result->get_error_code());
        self::assertSame(1, $database->getLockCallCount());
        self::assertSame(1, $database->cartReadCallCount());
        self::assertCount(1, $shutdownCallbacks);
    }

    public function testHostedCheckoutUsesTheSameServerSideCartSerialization(): void
    {
        $database = new InlineCheckoutCartLockWpdb();
        $database->lockResults = ['1'];
        $database->cartRows = [[
            'order_id' => '482',
            'stage' => 'intended',
        ]];
        $shutdownCallbacks = [];
        $guard = $this->guardForCart($database, $this->draftCart(), $shutdownCallbacks);

        $result = $guard->validate(true, ['_fct_pay_method' => 'ys_helcim']);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_checkout_already_started', $result->get_error_code());
        self::assertSame(1, $database->getLockCallCount());
        self::assertSame(1, $database->cartReadCallCount());
        self::assertCount(1, $shutdownCallbacks);
    }

    public function testExistingValidationErrorIsReturnedWithoutLoadingOrLockingTheCart(): void
    {
        $database = new InlineCheckoutCartLockWpdb();
        $loadCalls = 0;
        $existing = new \WP_Error('earlier_checkout_failure', 'Earlier validation failed.');
        $guard = new YSHelcimInlineCheckoutCartLock(
            $database,
            static function () use (&$loadCalls): object {
                ++$loadCalls;
                return (object) [];
            },
            static function (callable $callback): void {
                unset($callback);
            }
        );

        $result = $guard->validate($existing, ['_fct_pay_method' => 'ys_helcim_js']);

        self::assertSame($existing, $result);
        self::assertSame(0, $loadCalls);
        self::assertSame([], $database->preparedQueries);
    }

    /** @dataProvider nonInlinePaymentMethods */
    public function testFreshNonHelcimCheckoutNeverLocksWhenHelcimIsDisabled(array $data): void
    {
        $database = new InlineCheckoutCartLockWpdb();
        $loadCalls = 0;
        $guard = new YSHelcimInlineCheckoutCartLock(
            $database,
            static function () use (&$loadCalls): object {
                ++$loadCalls;
                return (object) [
                    'order_id' => null,
                    'stage' => 'draft',
                ];
            },
            static function (callable $callback): void {
                unset($callback);
            },
            null,
            null,
            static fn (): bool => false
        );

        self::assertTrue($guard->validate(true, $data));
        self::assertSame(1, $loadCalls);
        self::assertSame([], $database->preparedQueries);
    }

    public function testFreshNonHelcimCheckoutUsesTheSharedLockWhenHelcimIsOperational(): void
    {
        $database = new InlineCheckoutCartLockWpdb();
        $database->lockResults = ['1'];
        $database->cartRows = [[
            'order_id' => null,
            'stage' => 'draft',
        ]];
        $shutdownCallbacks = [];
        $guard = new YSHelcimInlineCheckoutCartLock(
            $database,
            static fn (): object => (object) [
                'cart_hash' => 'fresh-cross-provider-cart',
                'order_id' => null,
                'stage' => 'draft',
            ],
            static function (callable $callback) use (&$shutdownCallbacks): void {
                $shutdownCallbacks[] = $callback;
            },
            null,
            null,
            static fn (): bool => true
        );

        self::assertTrue($guard->validate(true, ['_fct_pay_method' => 'stripe']));
        self::assertSame(1, $database->getLockCallCount());
        self::assertSame(1, $database->cartReadCallCount());
        self::assertCount(1, $shutdownCallbacks);
    }

    /** @return iterable<string,array{0:array<string,mixed>}> */
    public static function nonInlinePaymentMethods(): iterable
    {
        yield 'missing field' => [[]];
        yield 'retired modal gateway' => [['_fct_pay_method' => 'ys_helcim_pay']];
        yield 'near match' => [['_fct_pay_method' => 'YS_HELCIM_JS']];
        yield 'nested value is not the contract' => [['form_data' => ['_fct_pay_method' => 'ys_helcim_js']]];
    }

    public function testExistingOrderWithoutATransactionUsesTheSerializedNativeRetryPath(): void
    {
        $database = new InlineCheckoutCartLockWpdb();
        $database->lockResults = ['1'];
        $database->cartRows = [[
            'order_id' => '481',
            'stage' => 'intended',
        ]];
        $shutdownCallbacks = [];
        $guard = $this->guardForCart(
            $database,
            (object) [
                'cart_hash' => 'retry-cart',
                'order_id' => '481',
                'stage' => 'intended',
            ],
            $shutdownCallbacks,
            static fn (int $orderId): null => null
        );

        self::assertTrue($guard->validate(true, ['_fct_pay_method' => 'ys_helcim_js']));
        self::assertSame(1, $database->getLockCallCount());
        self::assertSame(1, $database->cartReadCallCount());
        self::assertCount(1, $shutdownCallbacks);
    }

    public function testExistingOrderRetryRejectsCrossGatewayJournalBeforeFluentCartCanRewriteTransaction(): void
    {
        $database = new InlineCheckoutCartLockWpdb();
        $database->lockResults = ['1'];
        $database->cartRows = [[
            'order_id' => '481',
            'stage' => 'intended',
        ]];
        $shutdownCallbacks = [];
        $transaction = $this->transaction('ys_helcim');
        $attempt = $this->purchaseRow($transaction, 'ys_helcim');
        $loadedTransactionIds = [];
        $guard = $this->guardForCart(
            $database,
            (object) [
                'cart_hash' => 'gateway-switch-cart',
                'order_id' => '481',
                'stage' => 'intended',
            ],
            $shutdownCallbacks,
            static fn (int $orderId): object => $transaction,
            static function (int $transactionId) use (&$loadedTransactionIds, $attempt): array {
                $loadedTransactionIds[] = $transactionId;
                return [$attempt];
            }
        );

        $result = $guard->validate(true, ['_fct_pay_method' => 'ys_helcim_js']);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_checkout_payment_method_changed', $result->get_error_code());
        self::assertSame([9001], $loadedTransactionIds);
        self::assertSame(1, $database->getLockCallCount());
        self::assertSame(1, $database->cartReadCallCount());
        self::assertCount(1, $shutdownCallbacks);
    }

    public function testExistingOrderRetryIsSerializedBeforeReadingTheTransactionJournal(): void
    {
        $database = new InlineCheckoutCartLockWpdb();
        $database->lockResults = ['1'];
        $database->cartRows = [[
            'order_id' => '481',
            'stage' => 'intended',
        ]];
        $shutdownCallbacks = [];
        $transaction = $this->transaction('ys_helcim_js');
        $attempt = $this->purchaseRow($transaction, 'ys_helcim_js');
        $guard = $this->guardForCart(
            $database,
            (object) [
                'cart_hash' => 'serialized-existing-order-cart',
                'order_id' => '481',
                'stage' => 'intended',
            ],
            $shutdownCallbacks,
            static fn (int $orderId): object => $transaction,
            static fn (int $transactionId): array => [$attempt]
        );

        self::assertTrue($guard->validate(true, ['_fct_pay_method' => 'ys_helcim_js']));
        self::assertSame(1, $database->getLockCallCount());
        self::assertSame(1, $database->cartReadCallCount());
        self::assertCount(1, $shutdownCallbacks);
    }

    public function testExistingHelcimJournalRejectsSwitchingToANonHelcimGateway(): void
    {
        $database = new InlineCheckoutCartLockWpdb();
        $database->lockResults = ['1'];
        $database->cartRows = [[
            'order_id' => '481',
            'stage' => 'intended',
        ]];
        $shutdownCallbacks = [];
        $transaction = $this->transaction('ys_helcim_js');
        $attempt = $this->purchaseRow($transaction, 'ys_helcim_js');
        $guard = $this->guardForCart(
            $database,
            (object) [
                'cart_hash' => 'switch-to-stripe-cart',
                'order_id' => '481',
                'stage' => 'intended',
            ],
            $shutdownCallbacks,
            static fn (int $orderId): object => $transaction,
            static fn (int $transactionId): array => [$attempt]
        );

        $result = $guard->validate(true, ['_fct_pay_method' => 'stripe']);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_checkout_payment_method_changed', $result->get_error_code());
        self::assertSame(1, $database->getLockCallCount());
        self::assertSame(1, $database->cartReadCallCount());
        self::assertCount(1, $shutdownCallbacks);
    }

    /** @dataProvider unsafeJournalFreeTransactions */
    public function testExistingOrderWithoutJournalRejectsAProviderReceiptOrTerminalTransaction(
        string $status,
        string $vendorChargeId
    ): void {
        $database = new InlineCheckoutCartLockWpdb();
        $database->lockResults = ['1'];
        $database->cartRows = [[
            'order_id' => '481',
            'stage' => 'intended',
        ]];
        $shutdownCallbacks = [];
        $transaction = $this->transaction('ys_helcim_js');
        $transaction->status = $status;
        $transaction->vendor_charge_id = $vendorChargeId;
        $guard = $this->guardForCart(
            $database,
            (object) [
                'cart_hash' => 'unsafe-journal-free-cart',
                'order_id' => '481',
                'stage' => 'intended',
            ],
            $shutdownCallbacks,
            static fn (int $orderId): object => $transaction,
            static fn (int $transactionId): array => []
        );

        $result = $guard->validate(true, ['_fct_pay_method' => 'ys_helcim_js']);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_checkout_cart_unavailable', $result->get_error_code());
    }

    /** @return iterable<string,array{0:string,1:string}> */
    public static function unsafeJournalFreeTransactions(): iterable
    {
        yield 'provider receipt on pending transaction' => ['pending', '51935987'];
        yield 'succeeded without receipt' => ['succeeded', ''];
        yield 'authorized without receipt' => ['authorized', ''];
        yield 'refunded without receipt' => ['refunded', ''];
    }

    public function testExistingOrderRetryAllowsTheSameImmutableHelcimGatewayIdentity(): void
    {
        $database = new InlineCheckoutCartLockWpdb();
        $database->lockResults = ['1'];
        $database->cartRows = [[
            'order_id' => '481',
            'stage' => 'intended',
        ]];
        $shutdownCallbacks = [];
        $transaction = $this->transaction('ys_helcim_js');
        $attempt = $this->purchaseRow($transaction, 'ys_helcim_js');
        $guard = $this->guardForCart(
            $database,
            (object) [
                'cart_hash' => 'same-gateway-retry-cart',
                'order_id' => '481',
                'stage' => 'intended',
            ],
            $shutdownCallbacks,
            static fn (int $orderId): object => $transaction,
            static fn (int $transactionId): array => [$attempt]
        );

        self::assertTrue($guard->validate(true, ['_fct_pay_method' => 'ys_helcim_js']));
        self::assertSame(1, $database->getLockCallCount());
        self::assertSame(1, $database->cartReadCallCount());
        self::assertCount(1, $shutdownCallbacks);
    }

    public function testExistingOrderRetryWithoutAHelcimJournalKeepsTheNativeRetryPath(): void
    {
        $database = new InlineCheckoutCartLockWpdb();
        $database->lockResults = ['1'];
        $database->cartRows = [[
            'order_id' => '481',
            'stage' => 'intended',
        ]];
        $shutdownCallbacks = [];
        $transaction = $this->transaction('ys_helcim_js');
        $guard = $this->guardForCart(
            $database,
            (object) [
                'cart_hash' => 'journal-free-retry-cart',
                'order_id' => '481',
                'stage' => 'intended',
            ],
            $shutdownCallbacks,
            static fn (int $orderId): object => $transaction,
            static fn (int $transactionId): array => []
        );

        self::assertTrue($guard->validate(true, ['_fct_pay_method' => 'ys_helcim_js']));
        self::assertSame(1, $database->getLockCallCount());
        self::assertSame(1, $database->cartReadCallCount());
        self::assertCount(1, $shutdownCallbacks);
    }

    public function testExistingOrderWithoutJournalStillRejectsChangingHelcimGateways(): void
    {
        $database = new InlineCheckoutCartLockWpdb();
        $database->lockResults = ['1'];
        $database->cartRows = [[
            'order_id' => '481',
            'stage' => 'intended',
        ]];
        $shutdownCallbacks = [];
        $transaction = $this->transaction('ys_helcim');
        $guard = $this->guardForCart(
            $database,
            (object) [
                'cart_hash' => 'journal-free-gateway-switch-cart',
                'order_id' => '481',
                'stage' => 'intended',
            ],
            $shutdownCallbacks,
            static fn (int $orderId): object => $transaction,
            static fn (int $transactionId): array => []
        );

        $result = $guard->validate(true, ['_fct_pay_method' => 'ys_helcim_js']);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_checkout_payment_method_changed', $result->get_error_code());
        self::assertSame(1, $database->getLockCallCount());
        self::assertSame(1, $database->cartReadCallCount());
        self::assertCount(1, $shutdownCallbacks);
    }

    public function testJournalFreeNonHelcimRetryRemainsOwnedByItsNativeGateway(): void
    {
        $database = new InlineCheckoutCartLockWpdb();
        $database->lockResults = ['1'];
        $database->cartRows = [[
            'order_id' => '481',
            'stage' => 'intended',
        ]];
        $shutdownCallbacks = [];
        $transaction = $this->transaction('stripe');
        $transaction->status = 'pending';
        $transaction->vendor_charge_id = 'pi_native_gateway_receipt';
        $guard = $this->guardForCart(
            $database,
            (object) [
                'cart_hash' => 'stripe-native-retry-cart',
                'order_id' => '481',
                'stage' => 'intended',
            ],
            $shutdownCallbacks,
            static fn (int $orderId): object => $transaction,
            static fn (int $transactionId): array => []
        );

        self::assertTrue($guard->validate(true, ['_fct_pay_method' => 'stripe']));
        self::assertSame(1, $database->getLockCallCount());
        self::assertSame(1, $database->cartReadCallCount());
        self::assertCount(1, $shutdownCallbacks);
    }

    public function testExistingOrderRetryFailsClosedWhenTheJournalCannotBeRead(): void
    {
        $database = new InlineCheckoutCartLockWpdb();
        $database->lockResults = ['1'];
        $database->cartRows = [[
            'order_id' => '481',
            'stage' => 'intended',
        ]];
        $shutdownCallbacks = [];
        $transaction = $this->transaction('ys_helcim_js');
        $guard = $this->guardForCart(
            $database,
            (object) [
                'cart_hash' => 'journal-error-retry-cart',
                'order_id' => '481',
                'stage' => 'intended',
            ],
            $shutdownCallbacks,
            static fn (int $orderId): object => $transaction,
            static fn (int $transactionId): \WP_Error => new \WP_Error(
                'ys_helcim_journal_unavailable',
                'The journal is unavailable.'
            )
        );

        $result = $guard->validate(true, ['_fct_pay_method' => 'ys_helcim_js']);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_checkout_cart_unavailable', $result->get_error_code());
        self::assertSame(1, $database->getLockCallCount());
        self::assertSame(1, $database->cartReadCallCount());
        self::assertCount(1, $shutdownCallbacks);
    }

    /** @dataProvider failedLockResults */
    public function testLockFailureFailsClosedWithoutReadingTheCart(mixed $lockResult): void
    {
        $database = new InlineCheckoutCartLockWpdb();
        $database->lockResults = [$lockResult];
        $shutdownCallbacks = [];
        $guard = $this->guardForCart($database, $this->draftCart(), $shutdownCallbacks);

        $result = $guard->validate(true, ['_fct_pay_method' => 'ys_helcim_js']);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_checkout_busy', $result->get_error_code());
        self::assertSame(0, $database->cartReadCallCount());
        self::assertSame([], $shutdownCallbacks);
    }

    /** @return iterable<string,array{0:mixed}> */
    public static function failedLockResults(): iterable
    {
        yield 'timeout' => ['0'];
        yield 'sql null' => [null];
        yield 'unexpected boolean' => [true];
    }

    public function testLockDatabaseErrorFailsClosedEvenWhenMysqlReturnsOne(): void
    {
        $database = new InlineCheckoutCartLockWpdb();
        $database->lockResults = ['1'];
        $database->varErrors = ['simulated lock query failure'];
        $shutdownCallbacks = [];
        $guard = $this->guardForCart($database, $this->draftCart(), $shutdownCallbacks);

        $result = $guard->validate(true, ['_fct_pay_method' => 'ys_helcim_js']);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_checkout_busy', $result->get_error_code());
        self::assertSame(0, $database->cartReadCallCount());
    }

    /** @dataProvider invalidFreshCartRows */
    public function testMissingOrInvalidFreshCartStateFailsClosed(array|null|false $freshRow): void
    {
        $database = new InlineCheckoutCartLockWpdb();
        $database->lockResults = ['1'];
        $database->cartRows = [$freshRow];
        $shutdownCallbacks = [];
        $guard = $this->guardForCart($database, $this->draftCart(), $shutdownCallbacks);

        $result = $guard->validate(true, ['_fct_pay_method' => 'ys_helcim_js']);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_checkout_cart_unavailable', $result->get_error_code());
        self::assertCount(1, $shutdownCallbacks);
    }

    /** @return iterable<string,array{0:array{order_id:mixed,stage:mixed}|null|false}> */
    public static function invalidFreshCartRows(): iterable
    {
        yield 'missing row' => [null];
        yield 'query failure' => [false];
        yield 'unknown order id' => [['order_id' => 'not-an-id', 'stage' => 'draft']];
        yield 'completed without order' => [['order_id' => null, 'stage' => 'completed']];
        yield 'intended without order' => [['order_id' => null, 'stage' => 'intended']];
        yield 'missing stage' => [['order_id' => null, 'stage' => null]];
    }

    public function testFreshReadDatabaseErrorFailsClosed(): void
    {
        $database = new InlineCheckoutCartLockWpdb();
        $database->lockResults = ['1'];
        $database->varErrors = [''];
        $database->cartRows = [['order_id' => null, 'stage' => 'draft']];
        $database->rowErrors = ['simulated fresh read failure'];
        $shutdownCallbacks = [];
        $guard = $this->guardForCart($database, $this->draftCart(), $shutdownCallbacks);

        $result = $guard->validate(true, ['_fct_pay_method' => 'ys_helcim_js']);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_checkout_cart_unavailable', $result->get_error_code());
    }

    public function testValidDraftCartUsesOpaqueBoundedLockAndHoldsItUntilShutdown(): void
    {
        $rawCartHash = 'customer-visible-raw-cart-hash';
        $database = new InlineCheckoutCartLockWpdb();
        $database->lockResults = ['1', '1'];
        $database->cartRows = [
            ['order_id' => null, 'stage' => 'draft'],
            ['order_id' => '0', 'stage' => 'draft'],
        ];
        $shutdownCallbacks = [];
        $guard = $this->guardForCart($database, (object) [
            'cart_hash' => $rawCartHash,
            'order_id' => null,
            'stage' => 'draft',
        ], $shutdownCallbacks);

        self::assertTrue($guard->validate(true, ['_fct_pay_method' => 'ys_helcim_js']));
        self::assertTrue($guard->validate(true, ['_fct_pay_method' => 'ys_helcim_js']));

        self::assertSame(1, $database->getLockCallCount(), 'Re-entry must reuse the request-owned lock.');
        self::assertSame(2, $database->cartReadCallCount(), 'Every entry must re-read the serialized cart state.');
        self::assertCount(1, $shutdownCallbacks);

        $lockQuery = $database->firstPreparedQueryContaining('GET_LOCK');
        self::assertNotNull($lockQuery);
        $lockName = $lockQuery['args'][0];
        self::assertIsString($lockName);
        self::assertLessThanOrEqual(64, strlen($lockName));
        self::assertStringNotContainsString($rawCartHash, $lockName);
        self::assertMatchesRegularExpression('/^ysh_fct_cart_[a-f0-9]{48}$/', $lockName);
        self::assertSame(10, $lockQuery['args'][1]);

        $shutdownCallbacks[0]();
        $shutdownCallbacks[0]();
        self::assertSame(1, $database->releaseLockCallCount(), 'Release must be idempotent.');
        $releaseQuery = $database->firstPreparedQueryContaining('RELEASE_LOCK');
        self::assertNotNull($releaseQuery);
        self::assertSame($lockName, $releaseQuery['args'][0]);
    }

    public function testShutdownRegistrationFailureReleasesTheAcquiredMysqlLockAndFailsClosed(): void
    {
        $database = new InlineCheckoutCartLockWpdb();
        $database->lockResults = ['1', '1'];
        $guard = new YSHelcimInlineCheckoutCartLock(
            $database,
            fn (): object => $this->draftCart(),
            static function (callable $callback): never {
                unset($callback);
                throw new \RuntimeException('simulated shutdown registration failure');
            }
        );

        $result = $guard->validate(true, ['_fct_pay_method' => 'ys_helcim_js']);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_checkout_busy', $result->get_error_code());
        self::assertSame(1, $database->releaseLockCallCount());
        self::assertSame(0, $database->cartReadCallCount());
    }

    public function testCartLoaderAndInvalidInitialStateFailuresFailClosedBeforeLocking(): void
    {
        $database = new InlineCheckoutCartLockWpdb();
        $throwing = new YSHelcimInlineCheckoutCartLock(
            $database,
            static function (): never {
                throw new \RuntimeException('simulated cart loader failure');
            },
            static function (callable $callback): void {
                unset($callback);
            }
        );

        $loadFailure = $throwing->validate(true, ['_fct_pay_method' => 'ys_helcim_js']);
        self::assertInstanceOf(\WP_Error::class, $loadFailure);
        self::assertSame('ys_helcim_checkout_cart_unavailable', $loadFailure->get_error_code());

        foreach ([
            (object) ['cart_hash' => '', 'order_id' => null, 'stage' => 'draft'],
            (object) ['cart_hash' => 'cart', 'order_id' => null, 'stage' => 'completed'],
            (object) ['cart_hash' => 'cart', 'order_id' => 'invalid', 'stage' => 'draft'],
        ] as $invalidCart) {
            $callbacks = [];
            $result = $this->guardForCart($database, $invalidCart, $callbacks)
                ->validate(true, ['_fct_pay_method' => 'ys_helcim_js']);
            self::assertInstanceOf(\WP_Error::class, $result);
            self::assertSame('ys_helcim_checkout_cart_unavailable', $result->get_error_code());
        }

        self::assertSame([], $database->preparedQueries);
    }

    /**
     * @param list<callable> $shutdownCallbacks
     */
    private function guardForCart(
        InlineCheckoutCartLockWpdb $database,
        object $cart,
        array &$shutdownCallbacks,
        ?callable $transactionLoader = null,
        ?callable $purchaseAttemptLoader = null
    ): YSHelcimInlineCheckoutCartLock {
        return new YSHelcimInlineCheckoutCartLock(
            $database,
            static fn (): object => $cart,
            static function (callable $callback) use (&$shutdownCallbacks): void {
                $shutdownCallbacks[] = $callback;
            },
            $transactionLoader,
            $purchaseAttemptLoader
        );
    }

    private function transaction(string $gateway): object
    {
        return (object) [
            'id' => 9001,
            'order_id' => 481,
            'uuid' => 'transaction-uuid-9001',
            'payment_method' => $gateway,
            'transaction_type' => 'charge',
            'total' => 123,
            'currency' => 'USD',
            'payment_mode' => 'test',
            'status' => 'pending',
            'vendor_charge_id' => '',
        ];
    }

    /** @return array<string,mixed> */
    private function purchaseRow(object $transaction, string $gateway): array
    {
        $operation = YSHelcimPurchaseOperation::fromTransaction([
            'gateway' => $gateway,
            'order_id' => (int) $transaction->order_id,
            'transaction_id' => (int) $transaction->id,
            'transaction_uuid' => (string) $transaction->uuid,
            'amount' => (int) $transaction->total,
            'currency' => (string) $transaction->currency,
            'payment_mode' => (string) $transaction->payment_mode,
        ]);
        self::assertInstanceOf(YSHelcimPurchaseOperation::class, $operation);

        $record = $operation->repositoryRecord(
            '00000000-0000-4000-8000-000000009001',
            hash('sha256', 'attempt')
        );
        self::assertIsArray($record);

        return $record;
    }

    private function draftCart(): object
    {
        return (object) [
            'cart_hash' => 'draft-cart-hash',
            'order_id' => null,
            'stage' => 'draft',
        ];
    }
}

final class InlineCheckoutCartLockWpdb
{
    public string $prefix = 'wp_';
    public string $last_error = '';

    /** @var list<int|string|null|false> */
    public array $lockResults = [];

    /** @var list<string> */
    public array $varErrors = [];

    /** @var list<array{order_id:mixed,stage:mixed}|null|false> */
    public array $cartRows = [];

    /** @var list<string> */
    public array $rowErrors = [];

    /** @var list<array{query:string,args:list<mixed>}> */
    public array $preparedQueries = [];

    /** @var list<string> */
    public array $varQueries = [];

    /** @var list<string> */
    public array $rowQueries = [];

    public function prepare(string $query, mixed ...$args): string
    {
        $this->preparedQueries[] = [
            'query' => $query,
            'args' => $args,
        ];

        return $query . ' /* ' . json_encode($args, JSON_THROW_ON_ERROR) . ' */';
    }

    public function get_var(string $query): int|string|null|false
    {
        $this->varQueries[] = $query;
        $this->last_error = array_shift($this->varErrors) ?? '';
        return array_shift($this->lockResults);
    }

    /** @return array{order_id:mixed,stage:mixed}|null|false */
    public function get_row(string $query, string $output): array|null|false
    {
        unset($output);
        $this->rowQueries[] = $query;
        $this->last_error = array_shift($this->rowErrors) ?? '';
        return array_shift($this->cartRows);
    }

    public function getLockCallCount(): int
    {
        return count(array_filter(
            $this->preparedQueries,
            static fn (array $query): bool => str_contains($query['query'], 'GET_LOCK')
        ));
    }

    public function cartReadCallCount(): int
    {
        return count($this->rowQueries);
    }

    public function releaseLockCallCount(): int
    {
        return count(array_filter(
            $this->preparedQueries,
            static fn (array $query): bool => str_contains($query['query'], 'RELEASE_LOCK')
        ));
    }

    /** @return array{query:string,args:list<mixed>}|null */
    public function firstPreparedQueryContaining(string $needle): ?array
    {
        foreach ($this->preparedQueries as $query) {
            if (str_contains($query['query'], $needle)) {
                return $query;
            }
        }

        return null;
    }
}
