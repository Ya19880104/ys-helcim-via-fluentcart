<?php
/**
 * YS Helcim.js payment processor (FluentCart).
 *
 * Responsibilities:
 * 1. The fail-closed validation chain for the AJAX confirm (ys_helcim_fct_confirm_js):
 *    nonce → load the transaction → idempotency check → validateResponseHash →
 *    durable server-side v2 purchase (charge with cardToken) → strict provider proof → local aggregate binding.
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
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use YangSheep\Helcim\FluentCart\Support\YSHelcimLogger;
use YangSheep\Helcim\FluentCart\Support\YSHelcimTransactionId;

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
	private const MAX_RESPONSE_FIELDS_BYTES = 65536;

	private const MAX_RESPONSE_JSON_DEPTH = 16;

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

    /** Durable production purchase runtime. */
    private YSHelcimJsPurchaseRuntime $purchaseRuntime;

    /** Transaction-bound server confirmation signature. */
    private YSHelcimPurchaseConfirmationToken $confirmationTokens;

    /**
     * Constructor.
     *
     * @param YSHelcimJsSettings             $settings        The gateway settings object.
     * @param YSHelcimJsPurchaseRuntime|null       $purchaseRuntime    Injectable production runtime.
     * @param YSHelcimPurchaseConfirmationToken|null $confirmationTokens Injectable confirmation signer.
     */
    public function __construct(
        YSHelcimJsSettings $settings,
        ?YSHelcimJsPurchaseRuntime $purchaseRuntime = null,
        ?YSHelcimPurchaseConfirmationToken $confirmationTokens = null
    )
    {
        $this->settings = $settings;
        $this->purchaseRuntime = $purchaseRuntime ?? YSHelcimJsPurchaseRuntime::forSettings($settings);
        $this->confirmationTokens = $confirmationTokens ?? new YSHelcimPurchaseConfirmationToken();
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
        // ---- 1. Read the checkout confirmation nonce ----
        // Completed replays are handled before nonce enforcement because the
        // OrderPaid login transition can rotate the guest session. Pending
        // transactions are rejected on a missing or invalid nonce below.
        $nonce = self::postedText('nonce');

        // ---- 2. Load this gateway's charge transaction by uuid ----
        $transaction_uuid = self::postedText('transaction_uuid');

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

        // ---- 3. Exact local replay: require transaction ID plus paid-order proof ----
        if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
            $this->respondPurchaseResult(
                $transaction,
                $this->purchaseRuntime->executeInline($transaction, ''),
                true
            );
        }

        // A completed replay is mutation-free and may cross FluentCart's login
        // transition. Every pending path that can claim a provider operation
        // must remain bound to the checkout session that received this nonce.
        if (!$nonce || !wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            YSHelcimLogger::info('helcim.js confirm: nonce mismatch blocked before provider claim');
            $this->respondError(__('The payment confirmation session is invalid or expired. Please refresh and try again.', 'ys-helcim-via-fluentcart'), 403);
        }

        $confirmToken = self::postedText('confirm_token');
        if (!$this->confirmationTokens->verify($confirmToken, (string) $transaction->uuid, (int) $transaction->id)) {
            YSHelcimLogger::info('helcim.js confirm: transaction confirmation token rejected');
            $this->respondError(__('The payment confirmation session is invalid or expired. Please refresh and try again.', 'ys-helcim-via-fluentcart'), 403);
        }

        // ---- 4. Verify the helcim.js response hash (fail-closed) ----
        $response_fields = $this->readResponseFields();

        if (!$this->validateResponseHash($response_fields, (string) $transaction->payment_mode)) {
            YSHelcimLogger::error('helcim.js confirm: response hash verification failed', [
                'transaction_uuid' => $transaction_uuid,
            ]);
            $this->respondError(__('The card verification response is invalid. Please try again.', 'ys-helcim-via-fluentcart'), 400);
        }

        // A valid hash authenticates the SDK response; it does not turn an SDK
        // failure into a successful tokenization. Only the exact success flag
        // is allowed to reach the provider purchase boundary.
        if ('1' !== (string) ($response_fields['response'] ?? '')) {
            $this->respondError(__('The card could not be verified. Please check the card details and try again.', 'ys-helcim-via-fluentcart'), 422);
        }

        // ---- 5. Take the cardToken (security review M1: always use the response_fields.cardToken that "passed hash verification") ----
        // The verified card and the charged card must be the same one, so that the hash gate actually applies to the real charge.
        $card_token = (string) ($response_fields['cardToken'] ?? '');

        if ($card_token === '') {
            $this->respondError(__('The card token is missing. Please re-enter your card details.', 'ys-helcim-via-fluentcart'), 422);
        }

        // Tamper assertion: if the front end also passes card_token, it must match the verified value.
        $posted_token = self::postedText('card_token');

        if ($posted_token !== '' && !hash_equals($card_token, $posted_token)) {
            YSHelcimLogger::error('helcim.js confirm: card_token does not match the verified response — possible tampering', [
                'transaction_uuid' => $transaction_uuid,
            ]);
            $this->respondError(__('The card data verification did not match. Please try again.', 'ys-helcim-via-fluentcart'), 400);
        }

        // ---- 6. Durable remote-first purchase and exact local aggregate binding ----
        $result = $this->purchaseRuntime->executeInline($transaction, $card_token);
        $card_token = '';
        $this->respondPurchaseResult($transaction, $result, false);
    }

    /**
     * Emit one customer-safe response from the durable operation state.
     *
     * @param OrderTransaction                    $transaction      Server-loaded transaction.
     * @param array<string, mixed>|\WP_Error      $result           Runtime result.
     * @param bool                                $alreadyCompleted Local replay branch.
     * @return void Always ends with wp_send_json.
     */
    private function respondPurchaseResult(OrderTransaction $transaction, $result, bool $alreadyCompleted): void
    {
        if (is_wp_error($result)) {
            YSHelcimLogger::error('helcim.js confirm: purchase runtime rejected the request', [
                'transaction_uuid' => (string) $transaction->uuid,
                'error_code'       => $result->get_error_code(),
            ]);
            wp_send_json([
                'status'        => 'failed',
                'retry_allowed' => false,
                'message'       => __('The payment could not be started safely. Please contact the store administrator.', 'ys-helcim-via-fluentcart'),
            ], 503);
        }

        $status = is_array($result) ? (string) ($result['status'] ?? '') : '';
        if ($status === 'succeeded') {
            $provider_id = YSHelcimTransactionId::normalize($result['provider_transaction_id'] ?? null);
            $fresh = OrderTransaction::query()->where('id', $transaction->id)->first();
            if (
                $provider_id === null ||
                !$fresh ||
                $fresh->status !== Status::TRANSACTION_SUCCEEDED ||
                $provider_id !== YSHelcimTransactionId::normalize($fresh->vendor_charge_id ?? null)
            ) {
                wp_send_json([
                    'status'        => 'pending',
                    'retry_allowed' => false,
                    'message'       => __('Your payment needs verification. Do not submit another payment.', 'ys-helcim-via-fluentcart'),
                ], 409);
            }

            $response = $this->buildSuccessResponse(
                $fresh,
                $alreadyCompleted
                    ? __('Payment already completed. Redirecting to the receipt page.', 'ys-helcim-via-fluentcart')
                    : __('Payment successful! Redirecting to the receipt page.', 'ys-helcim-via-fluentcart')
            );
            if (is_wp_error($response)) {
                wp_send_json([
                    'status'        => 'pending',
                    'retry_allowed' => false,
                    'message'       => __('Your payment needs verification. Do not submit another payment.', 'ys-helcim-via-fluentcart'),
                ], 500);
            }

            wp_send_json($response, 200);
        }

        if ($status === 'declined') {
            wp_send_json([
                'status'        => 'failed',
                'retry_allowed' => true,
                'message'       => __('The payment was declined. Please use a different card.', 'ys-helcim-via-fluentcart'),
            ], 402);
        }

        if ($status === 'failed') {
            wp_send_json([
                'status'        => 'failed',
                'retry_allowed' => true,
                'message'       => __('The payment could not be completed. Please try again shortly.', 'ys-helcim-via-fluentcart'),
            ], 503);
        }

        wp_send_json([
            'status'        => 'pending',
            'retry_allowed' => false,
            'message'       => __('Your payment result is still being verified. Do not submit another payment.', 'ys-helcim-via-fluentcart'),
        ], 409);
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
     * @return array|\WP_Error
     */
    private function buildSuccessResponse(OrderTransaction $transaction, string $message)
    {
        $order = Order::query()->where('id', $transaction->order_id)->first();
		if (
			! $order instanceof Order ||
			(int) ($order->id ?? 0) !== (int) $transaction->order_id ||
			'' === trim((string) ($order->uuid ?? ''))
		) {
			return new \WP_Error(
				'ys_helcim_confirm_receipt_missing',
				__('The paid transaction receipt could not be loaded.', 'ys-helcim-via-fluentcart')
			);
		}

        return [
            'status'       => 'success',
            'redirect_url' => $transaction->getReceiptPageUrl(true),
            'message'      => $message,
            'order'        => [
                'uuid' => (string) $order->uuid,
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
     * @param array  $response_fields The helcim.js response fields.
     * @param string $payment_mode    Mode persisted on the FluentCart transaction.
     * @return bool True if verification passed.
     */
    public function validateResponseHash(array $response_fields, string $payment_mode): bool
    {
        $secret_key = $this->settings->getJsSecretKeyForMode($payment_mode);

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
     * Read and sanitize the response_fields from the AJAX request.
     *
     * Supports both delivery formats:
     * - A form array (response_fields[response]=1&response_fields[cardToken]=...).
     * - A JSON string (response_fields={"response":1,...}).
     *
     * Keeps only the five scalar fields required to verify the SDK response hash.
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
			if (strlen($raw) > self::MAX_RESPONSE_FIELDS_BYTES) {
				return [];
			}
			try {
				$decoded = json_decode($raw, true, self::MAX_RESPONSE_JSON_DEPTH, JSON_THROW_ON_ERROR);
			} catch (\JsonException $exception) {
				unset($exception);
				$decoded = null;
			}
            $raw = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($raw)) {
            return [];
        }

        $limits = [
            'response'   => 16,
            'cardNumber' => 64,
            'cardToken'  => 2048,
            'hash'       => 128,
            'xmlHash'    => 128,
        ];
        $fields = [];
        foreach ($raw as $key => $value) {
            if (!is_string($key) || !array_key_exists($key, $limits) || !is_scalar($value)) {
                continue;
            }
            $clean_value = sanitize_text_field((string) $value);
            if (strlen($clean_value) > $limits[$key]) {
                continue;
            }
            $fields[$key] = $clean_value;
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
		$raw = $_SERVER['REMOTE_ADDR'] ?? '';
		$ip = is_string($raw) ? sanitize_text_field(wp_unslash($raw)) : '';

        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return '127.0.0.1';
        }

        return $ip;
    }

	private static function postedText(string $key): string
	{
		if (!isset($_POST[$key]) || !is_string($_POST[$key])) {
			return '';
		}

		$value = wp_unslash($_POST[$key]);
		return is_string($value) ? sanitize_text_field($value) : '';
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
