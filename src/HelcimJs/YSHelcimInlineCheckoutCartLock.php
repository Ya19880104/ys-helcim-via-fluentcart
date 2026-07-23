<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\HelcimJs;

use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Models\OrderTransaction;
use YangSheep\Helcim\FluentCart\HelcimPay\YSHelcimPaySettings;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationRepository;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimPurchaseOperation;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Serializes Helcim order creation and all retries of an existing order.
 *
 * FluentCart 1.5.2 reads the cart before its pre-process validation filter and
 * writes cart.order_id only after creating an order and transaction. A MySQL
 * advisory lock held until PHP shutdown closes that request-lifetime TOCTOU
 * window without modifying FluentCart core.
 */
final class YSHelcimInlineCheckoutCartLock
{
    private const PAYMENT_METHODS = ['ys_helcim', 'ys_helcim_js'];
    private const LOCK_TIMEOUT_SECONDS = 10;
    private const LOCK_PREFIX = 'ysh_fct_cart_';

    private mixed $database;
    private \Closure $cartLoader;
    private \Closure $shutdownRegistrar;
    private \Closure $transactionLoader;
    private \Closure $purchaseAttemptLoader;
    private \Closure $helcimOperational;

    /** @var array<string,true> */
    private array $heldLocks = [];

    public function __construct(
        mixed $database = null,
        ?callable $cart_loader = null,
        ?callable $shutdown_registrar = null,
        ?callable $transaction_loader = null,
        ?callable $purchase_attempt_loader = null,
        ?callable $helcim_operational = null
    ) {
        if ($database === null) {
            global $wpdb;
            $database = $wpdb ?? null;
        }

        $this->database = $database;
        $this->cartLoader = $cart_loader !== null
            ? \Closure::fromCallable($cart_loader)
            : static fn (): mixed => CartHelper::getCart();
        $this->shutdownRegistrar = $shutdown_registrar !== null
            ? \Closure::fromCallable($shutdown_registrar)
            : static function (callable $callback): void {
                register_shutdown_function($callback);
            };
        $this->transactionLoader = $transaction_loader !== null
            ? \Closure::fromCallable($transaction_loader)
            : static fn (int $orderId): mixed => OrderTransaction::query()
                ->where('order_id', $orderId)
                ->first();
        if ($purchase_attempt_loader !== null) {
            $this->purchaseAttemptLoader = \Closure::fromCallable($purchase_attempt_loader);
        } else {
            $operations = new YSHelcimOperationRepository($database);
            $this->purchaseAttemptLoader = static fn (int $transactionId): mixed =>
                $operations->findPurchasesByIdentity($transactionId);
        }
        $this->helcimOperational = $helcim_operational !== null
            ? \Closure::fromCallable($helcim_operational)
            : static fn (): bool =>
                (new YSHelcimJsSettings())->isActive() ||
                (new YSHelcimPaySettings())->isActive();
    }

    /**
     * @param mixed               $validation Earlier validation-filter result.
     * @param array<string,mixed> $data       FluentCart checkout request data.
     */
    public function validate(mixed $validation, array $data): mixed
    {
        if (is_wp_error($validation)) {
            return $validation;
        }

        $requestedGateway = is_string($data['_fct_pay_method'] ?? null)
            ? trim((string) $data['_fct_pay_method'])
            : '';

        try {
            $cart = ($this->cartLoader)();
        } catch (\Throwable) {
            return $this->unavailableError();
        }

        if (!is_object($cart)) {
            return $this->unavailableError();
        }

        $cartHash = $cart->cart_hash ?? null;
        $orderId = $cart->order_id ?? null;
        $stage = $cart->stage ?? null;

        // A fresh checkout owned entirely by another provider is outside this
        // plugin's scope. Existing orders still pass through the lock so a
        // durable Helcim attempt cannot be bypassed by switching providers.
        if (
            !$this->isPositiveInteger($orderId) &&
            !$this->isHelcimGateway($requestedGateway) &&
            !$this->isHelcimOperational()
        ) {
            return $validation;
        }

        if (!is_string($cartHash) || $cartHash === '') {
            return $this->unavailableError();
        }

        if ($this->isPositiveInteger($orderId)) {
            $lockName = $this->lockName($cartHash);
            if (!$this->acquire($lockName)) {
                return $this->busyError();
            }

            $freshCart = $this->readFreshCart($cartHash);
            if (
                !is_array($freshCart) ||
                (int) ($freshCart['order_id'] ?? 0) !== (int) $orderId ||
                ($freshCart['stage'] ?? null) !== 'intended'
            ) {
                return $this->unavailableError();
            }

            return $this->validateExistingOrderRetry(
                $validation,
                $requestedGateway,
                (int) $orderId
            );
        }

        if (!$this->isEmptyOrderId($orderId) || $stage !== 'draft') {
            return $this->unavailableError();
        }

        $lockName = $this->lockName($cartHash);
        if (!$this->acquire($lockName)) {
            return $this->busyError();
        }

        $freshCart = $this->readFreshCart($cartHash);
        if (!is_array($freshCart)) {
            return $this->unavailableError();
        }

        $freshOrderId = $freshCart['order_id'] ?? null;
        if ($this->isPositiveInteger($freshOrderId)) {
            return new \WP_Error(
                'ys_helcim_checkout_already_started',
                __('This cart already has an order. Refresh the page before trying payment again.', 'ys-helcim-via-fluentcart')
            );
        }

        if (!$this->isEmptyOrderId($freshOrderId) || ($freshCart['stage'] ?? null) !== 'draft') {
            return $this->unavailableError();
        }

        return $validation;
    }

    private function validateExistingOrderRetry(
        mixed $validation,
        string $requestedGateway,
        int $orderId
    ): mixed {
        try {
            $transaction = ($this->transactionLoader)($orderId);
        } catch (\Throwable) {
            return $this->unavailableError();
        }

        // FluentCart will create the first transaction when an existing order
        // legitimately has none. There is no durable Helcim identity to guard.
        if ($transaction === null) {
            return $validation;
        }

        if (
            !is_object($transaction) ||
            (int) ($transaction->order_id ?? 0) !== $orderId ||
            !$this->isPositiveInteger($transaction->id ?? null) ||
            'charge' !== (string) ($transaction->transaction_type ?? '')
        ) {
            return $this->unavailableError();
        }

        try {
            $attempts = ($this->purchaseAttemptLoader)((int) $transaction->id);
        } catch (\Throwable) {
            return $this->unavailableError();
        }

        if (is_wp_error($attempts) || !is_array($attempts)) {
            return $this->unavailableError();
        }

        if ($attempts === []) {
            $existingGateway = trim((string) ($transaction->payment_method ?? ''));
            if (
                !$this->isHelcimGateway($existingGateway) &&
                !$this->isHelcimGateway($requestedGateway)
            ) {
                return $validation;
            }

            $status = strtolower(trim((string) ($transaction->status ?? '')));
            $vendorChargeId = trim((string) ($transaction->vendor_charge_id ?? ''));
            if (
                $vendorChargeId !== '' ||
                !in_array($status, ['pending', 'failed'], true)
            ) {
                return $this->unavailableError();
            }

            if (
                ($this->isHelcimGateway($existingGateway) || $this->isHelcimGateway($requestedGateway)) &&
                $existingGateway !== $requestedGateway
            ) {
                return $this->paymentMethodChangedError();
            }

            return $validation;
        }

        foreach ($attempts as $attempt) {
            if (!is_array($attempt)) {
                return $this->unavailableError();
            }

            if ((string) ($attempt['gateway'] ?? '') !== $requestedGateway) {
                return $this->paymentMethodChangedError();
            }
        }

        if (!$this->isHelcimGateway($requestedGateway)) {
            return $this->paymentMethodChangedError();
        }

        $operation = YSHelcimPurchaseOperation::fromTransaction([
            'gateway' => $requestedGateway,
            'order_id' => $orderId,
            'transaction_id' => (int) $transaction->id,
            'transaction_uuid' => (string) ($transaction->uuid ?? ''),
            'amount' => (int) ($transaction->total ?? 0),
            'currency' => (string) ($transaction->currency ?? ''),
            'payment_mode' => (string) ($transaction->payment_mode ?? ''),
        ]);
        if (is_wp_error($operation)) {
            return $this->unavailableError();
        }

        foreach ($attempts as $attempt) {
            if (!$operation->matchesIdentityRow($attempt)) {
                return $this->unavailableError();
            }
        }

        return $validation;
    }

    private function acquire(string $lockName): bool
    {
        if (isset($this->heldLocks[$lockName])) {
            return true;
        }

        if (!is_object($this->database) || !method_exists($this->database, 'prepare') || !method_exists($this->database, 'get_var')) {
            return false;
        }

        try {
            $query = $this->database->prepare(
                'SELECT GET_LOCK(%s, %d)',
                $lockName,
                self::LOCK_TIMEOUT_SECONDS
            );
            if (!is_string($query) || $query === '') {
                return false;
            }

            $acquired = $this->database->get_var($query);
            if ($this->hasDatabaseError() || ($acquired !== 1 && $acquired !== '1')) {
                return false;
            }

            // Mark ownership before registration so an exception from a custom
            // registrar can still release the connection-scoped MySQL lock.
            $this->heldLocks[$lockName] = true;
            ($this->shutdownRegistrar)(function () use ($lockName): void {
                $this->release($lockName);
            });
            return isset($this->heldLocks[$lockName]);
        } catch (\Throwable) {
            $this->release($lockName);
            return false;
        }
    }

    private function lockName(string $cartHash): string
    {
        return self::LOCK_PREFIX . substr(hash('sha256', $cartHash), 0, 48);
    }

    /** @return array<string,mixed>|null */
    private function readFreshCart(string $cartHash): ?array
    {
        if (!is_object($this->database) || !method_exists($this->database, 'prepare') || !method_exists($this->database, 'get_row')) {
            return null;
        }

        $prefix = $this->database->prefix ?? null;
        if (!is_string($prefix) || !preg_match('/^[A-Za-z0-9_]+$/', $prefix)) {
            return null;
        }

        try {
            $query = $this->database->prepare(
                "SELECT order_id, stage FROM {$prefix}fct_carts WHERE cart_hash = %s LIMIT 1",
                $cartHash
            );
            if (!is_string($query) || $query === '') {
                return null;
            }

            $row = $this->database->get_row($query, ARRAY_A);
            if ($this->hasDatabaseError() || !is_array($row)) {
                return null;
            }

            return $row;
        } catch (\Throwable) {
            return null;
        }
    }

    private function release(string $lockName): void
    {
        if (!isset($this->heldLocks[$lockName])) {
            return;
        }

        unset($this->heldLocks[$lockName]);

        if (!is_object($this->database) || !method_exists($this->database, 'prepare') || !method_exists($this->database, 'get_var')) {
            return;
        }

        try {
            $query = $this->database->prepare('SELECT RELEASE_LOCK(%s)', $lockName);
            if (is_string($query) && $query !== '') {
                $this->database->get_var($query);
            }
        } catch (\Throwable) {
            // Request shutdown must never be interrupted by a best-effort release.
        }
    }

    private function hasDatabaseError(): bool
    {
        return isset($this->database->last_error)
            && is_string($this->database->last_error)
            && $this->database->last_error !== '';
    }

    private function isEmptyOrderId(mixed $orderId): bool
    {
        return $orderId === null || $orderId === '' || $orderId === 0 || $orderId === '0';
    }

    private function isPositiveInteger(mixed $value): bool
    {
        if (is_int($value)) {
            return $value > 0;
        }

        return is_string($value)
            && preg_match('/^[1-9][0-9]*$/', $value) === 1;
    }

    private function isHelcimGateway(string $gateway): bool
    {
        return in_array($gateway, self::PAYMENT_METHODS, true);
    }

    private function isHelcimOperational(): bool
    {
        try {
            return (bool) ($this->helcimOperational)();
        } catch (\Throwable) {
            // If availability cannot be established, retain shared
            // serialization so a concurrent Helcim request cannot bypass it.
            return true;
        }
    }

    private function busyError(): \WP_Error
    {
        return new \WP_Error(
            'ys_helcim_checkout_busy',
            __('This checkout is already being processed. Please wait, refresh the page, and try again.', 'ys-helcim-via-fluentcart')
        );
    }

    private function paymentMethodChangedError(): \WP_Error
    {
        return new \WP_Error(
            'ys_helcim_checkout_payment_method_changed',
            __(
                'This checkout already contains a payment attempt from another payment method. Start a new checkout before changing payment methods.',
                'ys-helcim-via-fluentcart'
            )
        );
    }

    private function unavailableError(): \WP_Error
    {
        return new \WP_Error(
            'ys_helcim_checkout_cart_unavailable',
            __('The checkout cart could not be verified safely. Refresh the page before trying again.', 'ys-helcim-via-fluentcart')
        );
    }
}
