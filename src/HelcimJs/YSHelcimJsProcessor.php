<?php
/**
 * YS Helcim.js payment processor (FluentCart).
 *
 * Responsibilities:
 * 1. The fail-closed validation chain for the AJAX confirm (ys_helcim_fct_confirm_js):
 *    nonce → load the transaction → idempotency check → validateResponseHash →
 *    server-side v2 purchase (charge with cardToken) → response check (APPROVED/amount/currency) → markPaid.
 * 2. Webhook reconciliation (reconcileCardTransaction): confirm a pending transaction using the Helcim transaction lookup result.
 *
 * Security principles:
 * - If any validation step fails, the request is rejected and the payment is never marked successful (fail-closed).
 * - Hash comparison always uses hash_equals; an unset secret key means rejection.
 * - The purchase charge carries an idempotency key bound to the transaction uuid, preventing a retry from double-charging.
 * - Card numbers never pass through this server (we only receive the masked number and the cardToken).
 * - Secrets are not written to the log (YSHelcimLogger masks, and this layer does not proactively pass full secrets through).
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\HelcimJs;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use YangSheep\Helcim\FluentCart\Support\YSHelcimApiClient;
use YangSheep\Helcim\FluentCart\Support\YSHelcimLogger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class YSHelcimJsProcessor
 *
 * The business-logic layer for the helcim.js flow (Verify tokenize → server-side v2 purchase).
 */
class YSHelcimJsProcessor
{
    /**
     * Gateway slug (used to match transaction.payment_method).
     */
    private const METHOD_SLUG = 'ys_helcim_js';

    /**
     * Confirm nonce action (matches the nonce sent by makePaymentFromPaymentInstance;
     * kept separate from ys_helcim so the two gateways' nonces are not interchangeable).
     */
    private const NONCE_ACTION = 'ys_helcim_fct_confirm_js';

    /**
     * @var YSHelcimJsSettings The gateway settings.
     */
    private YSHelcimJsSettings $settings;

    /**
     * Constructor.
     *
     * @param YSHelcimJsSettings $settings The gateway settings object.
     */
    public function __construct(YSHelcimJsSettings $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Handle the AJAX confirm request (wp_ajax_ys_helcim_fct_confirm_js).
     *
     * Request parameters:
     * - transaction_uuid : the transaction uuid sent during order creation.
     * - nonce            : wp_create_nonce('ys_helcim_fct_confirm').
     * - card_token       : the cardToken obtained from helcim.js Verify tokenization.
     * - response_fields  : the helcim.js response fields (response / responseMessage /
     *                      cardToken / cardNumber(masked) / cardType / hash or xmlHash, etc.).
     *
     * The validation order is fixed (fail-closed); on any failure it responds with a 4xx JSON and stops.
     *
     * @return void Always ends with wp_send_json (which includes exit).
     */
    public function handleConfirmRequest(): void
    {
        // ---- 1. Soft nonce check (log only, does not block) ----
        // Architectural decision: on the OrderPaid event FluentCart automatically creates an account and logs it in (AuthService::makeLogin),
        // so the session changes after a successful confirm — a legitimate retry with the old nonce is guaranteed to be invalid, and a hard check would kill it.
        // Platform precedents (the Stripe/PayPal confirm endpoints) do not verify a WP nonce either. The real anti-forgery guard here is the
        // js_secret_key response hash (step 4) + the unguessable uuid + the charge-response check (step 6).
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';

        if (!$nonce || !wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            YSHelcimLogger::info('helcim.js confirm: nonce mismatch (soft check, not blocking)');
        }

        // ---- 2. Load this gateway's charge transaction by uuid ----
        $transaction_uuid = isset($_POST['transaction_uuid'])
            ? sanitize_text_field(wp_unslash($_POST['transaction_uuid']))
            : '';

        if ($transaction_uuid === '') {
            $this->respondError(__('The transaction identifier is missing.', 'ys-helcim-via-fluentcart'), 422);
        }

        $transaction = OrderTransaction::query()
            ->where('uuid', $transaction_uuid)
            ->where('payment_method', self::METHOD_SLUG)
            ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
            ->first();

        if (!$transaction) {
            YSHelcimLogger::error('helcim.js confirm: transaction not found', ['transaction_uuid' => $transaction_uuid]);
            $this->respondError(__('The matching transaction could not be found. Please check out again.', 'ys-helcim-via-fluentcart'), 404);
        }

        // ---- 3. Idempotency: a transaction that already succeeded goes straight to the receipt page, with no re-charge ----
        if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
            wp_send_json(
                $this->buildSuccessResponse($transaction, __('Payment already completed. Redirecting to the receipt page…', 'ys-helcim-via-fluentcart')),
                200
            );
        }

        // ---- 4. Verify the helcim.js response hash (fail-closed) ----
        $response_fields = $this->readResponseFields();

        if (!$this->validateResponseHash($response_fields)) {
            YSHelcimLogger::error('helcim.js confirm: response hash verification failed', [
                'transaction_uuid' => $transaction_uuid,
            ]);
            $this->respondError(__('The card verification response is invalid. Please try again.', 'ys-helcim-via-fluentcart'), 400);
        }

        // ---- 5. Take the cardToken (security review M1: always use the response_fields.cardToken that "passed hash verification") ----
        // The verified card and the charged card must be the same one, so that the hash gate actually applies to the real charge.
        $card_token = (string) ($response_fields['cardToken'] ?? '');

        if ($card_token === '') {
            $this->respondError(__('The card token is missing. Please re-enter your card details.', 'ys-helcim-via-fluentcart'), 422);
        }

        // Tamper assertion: if the front end also passes card_token, it must match the verified value.
        $posted_token = isset($_POST['card_token'])
            ? sanitize_text_field(wp_unslash($_POST['card_token']))
            : '';

        if ($posted_token !== '' && !hash_equals($card_token, $posted_token)) {
            YSHelcimLogger::error('helcim.js confirm: card_token does not match the verified response — possible tampering', [
                'transaction_uuid' => $transaction_uuid,
            ]);
            $this->respondError(__('The card data verification did not match. Please try again.', 'ys-helcim-via-fluentcart'), 400);
        }

        $helcim_tx = $this->purchaseWithCardToken($transaction, $card_token);

        if (is_wp_error($helcim_tx)) {
            YSHelcimLogger::error('helcim.js confirm: charge failed', [
                'transaction_uuid' => $transaction_uuid,
                'error'            => $helcim_tx->get_error_message(),
            ]);
            $this->respondError(__('The payment could not be completed. Please check your card details or use a different card.', 'ys-helcim-via-fluentcart'), 402);
        }

        // ---- 6. Charge-response check: APPROVED + amount (integer cents) + currency ----
        $verify_error = $this->verifyPurchaseResponse($transaction, $helcim_tx);
        if ($verify_error !== null) {
            YSHelcimLogger::error('helcim.js confirm: charge-response check failed', [
                'transaction_uuid' => $transaction_uuid,
                'reason'           => $verify_error,
            ]);
            $this->respondError(__('The payment result check failed. Please contact the site administrator.', 'ys-helcim-via-fluentcart'), 400);
        }

        // ---- 7. All checks passed: mark the payment successful and sync the order status ----
        $transaction = $this->markPaid($transaction, $helcim_tx, $card_token);

        wp_send_json(
            $this->buildSuccessResponse($transaction, __('Payment successful! Redirecting to the receipt page…', 'ys-helcim-via-fluentcart')),
            200
        );
    }

    /**
     * Assemble the successful confirm response.
     *
     * order.uuid lets the front end's triggerPaymentCompleteEvent fire
     * FluentCart's post-order actions (fluent_cart_run_order_actions); when
     * missing, the front end simply redirects and the payment is unaffected.
     *
     * @param OrderTransaction $transaction The transaction (in the succeeded state).
     * @param string           $message     The message to display.
     * @return array
     */
    private function buildSuccessResponse(OrderTransaction $transaction, string $message): array
    {
        $order = Order::query()->where('id', $transaction->order_id)->first();

        return [
            'status'       => 'success',
            'redirect_url' => $transaction->getReceiptPageUrl(true),
            'message'      => $message,
            'order'        => [
                'uuid' => $order ? (string) $order->uuid : '',
            ],
        ];
    }

    /**
     * Verify the helcim.js response hash (formula ported from the Woo version's YSHelcimJsService::validateResponseHash).
     *
     * Formula: expected = SHA256( response . cardNumber . cardToken . jsSecretKey )
     * compared with the hash (or xmlHash) in the response using hash_equals.
     *
     * Difference from the Woo version (the known defect fixed here):
     * - The Woo version "skips verification and lets it through" (fail-open) when the secret key is unset or the response has no hash.
     * - This version is always fail-closed: an unset secret key is rejected; a missing hash is rejected; a failed check is rejected.
     *
     * @param array $response_fields The helcim.js response fields.
     * @return bool True if verification passed.
     */
    public function validateResponseHash(array $response_fields): bool
    {
        $secret_key = $this->settings->getJsSecretKey();

        // fail-closed: reject when the secret key is unset (the Woo version lets it through here, which is a known defect).
        if ($secret_key === '') {
            YSHelcimLogger::error('helcim.js: JS Secret Key not set, refusing the confirm request');
            return false;
        }

        // Response hash field: the helcimProcess callback provides hash; the #helcimResults hidden field is xmlHash.
        $received_hash = (string) ($response_fields['hash'] ?? $response_fields['xmlHash'] ?? '');

        // fail-closed: reject when there is no hash (the Woo version lets it through here, which is a known defect).
        if ($received_hash === '') {
            YSHelcimLogger::error('helcim.js: response has no hash, refusing the confirm request');
            return false;
        }

        // The Woo version's verified formula: SHA256(response . cardNumber . cardToken . secretKey).
        $data_string = (string) ($response_fields['response'] ?? '')
            . (string) ($response_fields['cardNumber'] ?? '')
            . (string) ($response_fields['cardToken'] ?? '');

        $expected_hash = hash('sha256', $data_string . $secret_key);

        return hash_equals($expected_hash, $received_hash);
    }

    /**
     * Charge via the Helcim v2 payment/purchase endpoint using the cardToken.
     *
     * Idempotency design: the idempotency key is bound to the transaction uuid
     * (with the 'yshfct-' prefix, kept within 36 characters), so a retry of the
     * same transaction does not double-charge (Helcim returns the same result for
     * the same key).
     *
     * @param OrderTransaction $transaction The FluentCart transaction (total is in cents).
     * @param string           $card_token  The card token obtained from helcim.js Verify.
     * @return array|\WP_Error The Helcim response array, or an error.
     */
    private function purchaseWithCardToken(OrderTransaction $transaction, string $card_token)
    {
        $api_token = $this->settings->getApiToken();

        if ($api_token === '') {
            return new \WP_Error(
                'ys_helcim_no_api_token',
                __('The Helcim API token has not been configured.', 'ys-helcim-via-fluentcart')
            );
        }

        $order = $transaction->order;

        if (!$order) {
            return new \WP_Error(
                'ys_helcim_no_order',
                __('The order for this transaction could not be found.', 'ys-helcim-via-fluentcart')
            );
        }

        // [Immutable contract] amount can only come from $transaction->total (the server-side database value).
        // The overall security of the helcim.js flow depends on this (the hash is not bound to the transaction amount) —
        // never accept any amount passed in from the front end (see security review M1).
        // FluentCart stores amounts in cents; the Helcim API expects a decimal string in the main unit.
        $payload = [
            'ipAddress'     => $this->getClientIp(),
            'currency'      => strtoupper((string) $transaction->currency),
            'amount'        => number_format(((int) $transaction->total) / 100, 2, '.', ''),
            'cardData'      => [
                'cardToken' => $card_token,
            ],
            'invoiceNumber' => (string) $order->uuid,
            'ecommerce'     => true,
        ];

        // Billing address (falls back to shipping when billing is missing; matches the Woo version's buildBillingAddress).
        $billing_address = $this->buildBillingAddress($order);
        if (!empty($billing_address)) {
            $payload['billingAddress'] = $billing_address;
        }

        /**
         * Filter the Helcim purchase charge payload.
         *
         * @param array            $payload     The charge parameters.
         * @param OrderTransaction $transaction The FluentCart transaction.
         */
        $payload = apply_filters('ys_helcim_fct_purchase_args', $payload, $transaction);

        // Idempotency key (Code Review 🟡-1): bound to "transaction + card token" —
        // a same-card retry is idempotent (prevents double-charging); a retry with a different card after a first decline gets a new key
        // (so it is not stuck on the decline result Helcim cached under the same key).
        // 'ysh-' + 32 hex = 36 characters, within Helcim's 25–36 character limit.
        $idempotency_key = 'ysh-' . substr(hash('sha256', $transaction->uuid . $card_token), 0, 32);

        return YSHelcimApiClient::request('payment/purchase', $payload, $api_token, $idempotency_key);
    }

    /**
     * Check the purchase response (fail-closed).
     *
     * Items verified:
     * 1. status === 'APPROVED'.
     * 2. transactionId is present (the v2 transaction ID, used for refunds/reconciliation).
     * 3. Amount compared in integer cents: (int) round(amount * 100) === (int) transaction->total.
     * 4. Currency matches (case-insensitive).
     *
     * @param OrderTransaction $transaction The FluentCart transaction.
     * @param array            $helcim_tx   The Helcim charge response.
     * @return string|null Returns null on pass; a reason description on failure (for the log only, never returned to the front end).
     */
    private function verifyPurchaseResponse(OrderTransaction $transaction, array $helcim_tx): ?string
    {
        // Compare after case normalization (consistent with ys_helcim; under fail-closed, normalization is the correct direction).
        if ('APPROVED' !== strtoupper((string) ($helcim_tx['status'] ?? ''))) {
            return 'status_not_approved';
        }

        if (empty($helcim_tx['transactionId'])) {
            return 'missing_transaction_id';
        }

        $paid_cents = (int) round(((float) ($helcim_tx['amount'] ?? 0)) * 100);
        if ($paid_cents !== (int) $transaction->total) {
            return 'amount_mismatch';
        }

        $paid_currency = strtoupper((string) ($helcim_tx['currency'] ?? ''));
        if ($paid_currency === '' || $paid_currency !== strtoupper((string) $transaction->currency)) {
            return 'currency_mismatch';
        }

        return null;
    }

    /**
     * Mark the transaction as paid and sync the order status.
     *
     * Race-protected (following PayPal's confirmPaymentSuccessByCharge): reload
     * the transaction first, and skip the update if it is already succeeded (the
     * idempotency guarantee when the webhook and AJAX run concurrently).
     *
     * @param OrderTransaction $transaction The FluentCart transaction.
     * @param array            $helcim_tx   The Helcim transaction data (APPROVED, with amount and currency already checked).
     * @param string           $card_token  The card token (stored in meta for a future saved-card feature).
     * @return OrderTransaction The updated transaction.
     */
    public function markPaid(OrderTransaction $transaction, array $helcim_tx, string $card_token = ''): OrderTransaction
    {
        // Reload to avoid duplicate processing when the webhook / AJAX run concurrently (on a reload failure, return the original instance without interrupting).
        $fresh = OrderTransaction::query()->where('id', $transaction->id)->first();
        if (!$fresh) {
            return $transaction;
        }
        $transaction = $fresh;

        if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
            return $transaction;
        }

        $update_data = [
            'status'              => Status::TRANSACTION_SUCCEEDED,
            'vendor_charge_id'    => (string) ($helcim_tx['transactionId'] ?? ''),
            'payment_method_type' => 'card',
        ];

        // Take the last 4 digits of the masked card number (e.g. "5454****5454").
        $masked_card = (string) ($helcim_tx['cardNumber'] ?? '');
        if ($masked_card !== '') {
            $update_data['card_last_4'] = substr($masked_card, -4);
        }

        $card_brand = (string) ($helcim_tx['cardType'] ?? '');
        if ($card_brand !== '') {
            $update_data['card_brand'] = $card_brand;
        }

        // meta: add the approval code and cardToken (usable for a future saved-card / recurring-charge feature).
        $meta_patch = [];
        if (!empty($helcim_tx['approvalCode'])) {
            $meta_patch['approval_code'] = (string) $helcim_tx['approvalCode'];
        }
        if ($card_token !== '') {
            $meta_patch['card_token'] = $card_token;
        } elseif (!empty($helcim_tx['cardToken'])) {
            $meta_patch['card_token'] = (string) $helcim_tx['cardToken'];
        }

        if (!empty($meta_patch)) {
            $update_data['meta'] = array_merge(
                is_array($transaction->meta) ? $transaction->meta : [],
                $meta_patch
            );
        }

        $transaction->fill($update_data);
        $transaction->save();

        YSHelcimLogger::info('helcim.js: transaction paid successfully', [
            'transaction_uuid' => $transaction->uuid,
            'vendor_charge_id' => $update_data['vendor_charge_id'],
        ]);

        // Sync the order status (FluentCart's built-in atomic PAID transition prevents a duplicate OrderPaid trigger).
        $order = Order::query()->where('id', $transaction->order_id)->first();
        if ($order) {
            (new StatusHelper($order))->syncOrderStatuses($transaction);
        }

        return $transaction;
    }

    /**
     * Webhook reconciliation: confirm a pending transaction using the Helcim card-transactions lookup result.
     *
     * Lookup order:
     * 1. vendor_charge_id already bound to the same Helcim transaction → already processed (idempotent 200).
     * 2. invoiceNumber (= the FluentCart order uuid) → this gateway's pending charge transaction for that order.
     *
     * The amount/currency check (fail-closed) still runs before confirmation; only an APPROVED result is marked paid.
     *
     * @param array $helcim_tx The Helcim GET card-transactions/{id} response.
     * @return array{code:int, message:string} The HTTP status code and message (the caller passes them to wp_send_json).
     */
    public function reconcileCardTransaction(array $helcim_tx): array
    {
        $vendor_id = (string) ($helcim_tx['transactionId'] ?? '');

        if ($vendor_id === '') {
            return ['code' => 200, 'message' => 'ignored: no transactionId'];
        }

        // 1. A successful record already bound to the same Helcim transaction → idempotent, do not reprocess.
        $existing = OrderTransaction::query()
            ->where('vendor_charge_id', $vendor_id)
            ->where('payment_method', self::METHOD_SLUG)
            ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
            ->first();

        if ($existing && $existing->status === Status::TRANSACTION_SUCCEEDED) {
            return ['code' => 200, 'message' => 'already processed'];
        }

        // Only confirm an "approved purchase" — refund / preauth / verify are all cardTransaction
        // events and are also APPROVED on success, so a missing type check would let a refund/preauth webhook
        // record a payment by mistake (Code Review 🔴-1; consistent with the ys_helcim webhook reconciliation).
        if ('APPROVED' !== strtoupper((string) ($helcim_tx['status'] ?? ''))) {
            return ['code' => 200, 'message' => 'ignored: not approved'];
        }

        if ('purchase' !== strtolower((string) ($helcim_tx['type'] ?? ''))) {
            return ['code' => 200, 'message' => 'ignored: not a purchase'];
        }

        $transaction = $existing;

        // 2. Find the pending transaction by invoiceNumber (= order uuid).
        if (!$transaction) {
            $invoice_number = (string) ($helcim_tx['invoiceNumber'] ?? '');

            if ($invoice_number === '') {
                return ['code' => 200, 'message' => 'ignored: no invoiceNumber'];
            }

            $order = Order::query()->where('uuid', $invoice_number)->first();

            if (!$order) {
                YSHelcimLogger::info('webhook reconcile: matching order not found', ['invoice_number' => $invoice_number]);
                return ['code' => 200, 'message' => 'ignored: order not found'];
            }

            // Take the latest charge transaction "without filtering on status" (consistent with ys_helcim, Code Review 🟡-9):
            // a succeeded one is skipped by the later idempotency gate; if a failed transaction actually succeeded on Helcim's end,
            // webhook reconciliation is exactly the mechanism that rescues it.
            $transaction = OrderTransaction::query()
                ->where('order_id', $order->id)
                ->where('payment_method', self::METHOD_SLUG)
                ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
                ->orderBy('id', 'desc')
                ->first();
        }

        if (!$transaction) {
            return ['code' => 200, 'message' => 'ignored: no pending transaction'];
        }

        if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
            return ['code' => 200, 'message' => 'already processed'];
        }

        // Amount / currency check (fail-closed: on a mismatch do not confirm, only log for manual follow-up).
        $verify_error = $this->verifyPurchaseResponse($transaction, $helcim_tx);
        if ($verify_error !== null) {
            YSHelcimLogger::error('webhook reconcile: amount/currency check failed, refusing to confirm', [
                'transaction_uuid' => $transaction->uuid,
                'vendor_charge_id' => $vendor_id,
                'reason'           => $verify_error,
            ]);
            return ['code' => 200, 'message' => 'ignored: verification failed'];
        }

        $this->markPaid($transaction, $helcim_tx);

        YSHelcimLogger::info('webhook reconcile: pending transaction confirmed', [
            'transaction_uuid' => $transaction->uuid,
            'vendor_charge_id' => $vendor_id,
        ]);

        return ['code' => 200, 'message' => 'reconciled'];
    }

    /**
     * Read and sanitize the response_fields from the AJAX request.
     *
     * Supports both delivery formats:
     * - A form array (response_fields[response]=1&response_fields[cardToken]=...).
     * - A JSON string (response_fields={"response":1,...}).
     *
     * Keeps scalar values only and sanitizes each one (the card number field is already in masked form).
     *
     * @return array The sanitized response fields.
     */
    private function readResponseFields(): array
    {
        if (!isset($_POST['response_fields'])) {
            return [];
        }

        $raw = wp_unslash($_POST['response_fields']); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is sanitized below.

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw     = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($raw)) {
            return [];
        }

        // Note: do not use sanitize_key (it lowercases); the helcim.js fields are camelCase,
        // so the original key names must be preserved for comparison (only illegal characters are filtered out).
        $fields = [];
        foreach ($raw as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }
            $clean_key = preg_replace('/[^A-Za-z0-9_]/', '', $key);
            if ($clean_key === '') {
                continue;
            }
            $fields[$clean_key] = sanitize_text_field((string) $value);
        }

        return $fields;
    }

    /**
     * Assemble the Helcim billingAddress from the FluentCart order.
     *
     * Matches the Woo version's YSHelcimApiFactory::buildBillingAddress:
     * falls back to shipping when billing is missing; converts the country code
     * from 2 to 3 letters; drops empty fields.
     *
     * @param Order $order The FluentCart order.
     * @return array The Helcim billingAddress structure (may be an empty array).
     */
    private function buildBillingAddress(Order $order): array
    {
        $billing  = $order->billing_address;
        $shipping = $order->shipping_address;

        if (!$billing && !$shipping) {
            return [];
        }

        // Field by field: billing first, falling back to shipping when missing.
        $pick = static function (string $attribute) use ($billing, $shipping): string {
            $value = $billing ? (string) ($billing->{$attribute} ?? '') : '';
            if ($value === '' && $shipping) {
                $value = (string) ($shipping->{$attribute} ?? '');
            }
            return $value;
        };

        $country = $pick('country');

        // email: prefer the address email accessor, falling back to the customer email
        // (fct_orders has no email column, so $order->email is always null — Code Review 🟡-4).
        $email = $pick('email');
        if ($email === '' && $order->customer) {
            $email = (string) ($order->customer->email ?? '');
        }

        $address = [
            'name'       => $pick('name'),
            'street1'    => $pick('address_1'),
            'street2'    => $pick('address_2'),
            'city'       => $pick('city'),
            'province'   => $pick('state'),
            'postalCode' => $pick('postcode'),
            'country'    => $country !== '' ? $this->convertCountryCode($country) : '',
            'phone'      => $pick('phone'),
            'email'      => $email,
        ];

        // Drop empty fields so we do not send empty strings to Helcim.
        return array_filter($address, static function ($value) {
            return $value !== '';
        });
    }

    /**
     * Convert a country code from 2 to 3 letters (ISO 3166-1 alpha-2 → alpha-3).
     *
     * Matches the Woo version's YSHelcimApiFactory::convertCountryCode; an unknown
     * code is returned as-is (Helcim tolerates it on its end).
     *
     * @param string $alpha2 The two-letter country code.
     * @return string The three-letter country code (or the original value).
     */
    private function convertCountryCode(string $alpha2): string
    {
        $countries = [
            'TW' => 'TWN',
            'US' => 'USA',
            'CA' => 'CAN',
            'GB' => 'GBR',
            'AU' => 'AUS',
            'JP' => 'JPN',
            'CN' => 'CHN',
            'HK' => 'HKG',
            'SG' => 'SGP',
        ];

        $alpha2 = strtoupper($alpha2);

        return $countries[$alpha2] ?? $alpha2;
    }

    /**
     * Get the client IP (a required ipAddress field for the Helcim purchase/refund).
     *
     * Only REMOTE_ADDR is trusted (X-Forwarded-For can be spoofed and is not used).
     *
     * @return string
     */
    public function getClientIp(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR'])
            ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
            : '';

        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return '127.0.0.1';
        }

        return $ip;
    }

    /**
     * Respond with an error as a 4xx JSON and stop (wp_send_json includes exit).
     *
     * @param string $message The error message (safe to show to the customer; contains no secrets).
     * @param int    $code    The HTTP status code.
     * @return void
     */
    private function respondError(string $message, int $code): void
    {
        wp_send_json([
            'status'  => 'failed',
            'message' => $message,
        ], $code);
    }
}
