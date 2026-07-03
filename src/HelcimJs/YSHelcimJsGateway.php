<?php
/**
 * YS Helcim.js Gateway (FluentCart).
 *
 * The helcim.js inline card form payment method (slug: ys_helcim_js).
 *
 * Flow (Paddle-style custom checkout button):
 * 1. The checkout page enqueues the helcim.js SDK plus our own checkout script.
 * 2. The front end renders the card form inside the payment container (the fields
 *    have an id but no name, so card data never touches our server).
 * 3. Clicking pay → orderHandler() creates the order → makePaymentFromPaymentInstance()
 *    returns payment_data (transaction_uuid / confirm_nonce / js_token / test_mode).
 * 4. The front end runs helcimProcess() to tokenize and obtain a cardToken.
 * 5. AJAX ys_helcim_fct_confirm_js → YSHelcimJsProcessor charges server-side (fail-closed).
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\HelcimJs;

use FluentCart\Api\CurrencySettings;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Services\Payments\PaymentInstance;
use YangSheep\Helcim\FluentCart\Support\YSHelcimApiClient;
use YangSheep\Helcim\FluentCart\Support\YSHelcimLogger;
use YangSheep\Helcim\FluentCart\Webhook\YSHelcimWebhookVerifier;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class YSHelcimJsGateway
 *
 * FluentCart payment method: credit card (Helcim inline form).
 */
class YSHelcimJsGateway extends AbstractPaymentGateway
{
    /**
     * @var array Supported features (subscriptions are not supported: Helcim has no native subscription engine).
     */
    public array $supportedFeatures = ['payment', 'refund', 'webhook', 'custom_payment'];

    /**
     * Constructor.
     *
     * Registers as a custom-checkout-button gateway (the front end renders its own button, and clicking it goes through orderHandler to create the order).
     */
    public function __construct()
    {
        parent::__construct(new YSHelcimJsSettings());

        // Matching the PayPal pattern: add to the custom checkout button list.
        add_filter('fluent_cart/payment_methods_with_custom_checkout_buttons', function ($methods) {
            $methods[] = 'ys_helcim_js';
            return $methods;
        });
    }

    /**
     * Gateway metadata.
     *
     * GatewayManager::getAllMeta() validates that these are present:
     * brand_color / description / icon / logo / route / status / title.
     *
     * @return array
     */
    public function meta(): array
    {
        return [
            'title'              => __('Credit card (Helcim inline form)', 'ys-helcim-via-fluentcart'),
            'route'              => 'ys_helcim_js',
            'slug'               => 'ys_helcim_js',
            'label'              => 'Helcim.js',
            'admin_title'        => 'Helcim.js (inline form)',
            'description'        => __('Accept credit cards with the Helcim.js inline form. Card numbers are tokenized directly in the browser, so card data never passes through your server. USD / CAD only.', 'ys-helcim-via-fluentcart'),
            'logo'               => YS_HELCIM_FCT_URL . 'assets/images/helcim-logo.svg',
            'icon'               => YS_HELCIM_FCT_URL . 'assets/images/helcim-icon.svg',
            'brand_color'        => '#5B4FE9',
            'status'             => $this->settings->get('is_active') === 'yes',
            'upcoming'           => false,
            'supported_features' => $this->supportedFeatures,
        ];
    }

    /**
     * Gateway boot (called during GatewayManager::register()).
     *
     * Hooks up the AJAX confirm endpoint and the debug logging switch.
     *
     * @return void
     */
    public function boot()
    {
        // Debug mode only turns logging on, never off: this avoids overriding the other Helcim gateway's debug setting.
        if ($this->settings->isDebugMode()) {
            YSHelcimLogger::set_enabled(true);
        }

        add_action('wp_ajax_ys_helcim_fct_confirm_js', [$this, 'ajaxConfirmJsPayment']);
        add_action('wp_ajax_nopriv_ys_helcim_fct_confirm_js', [$this, 'ajaxConfirmJsPayment']);
    }

    /**
     * AJAX: confirm a helcim.js payment (charged server-side after tokenization).
     *
     * The actual validation chain is delegated to YSHelcimJsProcessor (fail-closed).
     *
     * @return void Ends with wp_send_json.
     */
    public function ajaxConfirmJsPayment(): void
    {
        (new YSHelcimJsProcessor($this->settings))->handleConfirmRequest();
    }

    /**
     * Payment initialization after order creation (called after the checkout button click creates the order via orderHandler).
     *
     * Guard order: subscription → currency → amount → credential completeness
     * (fail fast, so we do not discover a missing setting only after the customer
     * has entered their card).
     *
     * The successful return envelope follows FluentCart conventions
     * (CheckoutApi::finalizeOrder sends it to the front end as-is via
     * wp_send_json), with custom data under the payment_data key.
     *
     * @param PaymentInstance $paymentInstance The FluentCart payment instance.
     * @return array|\WP_Error
     */
    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance)
    {
        $order       = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;

        if (!$order || !$transaction) {
            return new \WP_Error(
                'ys_helcim_js_no_transaction',
                __('Payment initialization failed. Please refresh the page and try again.', 'ys-helcim-via-fluentcart')
            );
        }

        // Subscription products are not supported (v1).
        if ($paymentInstance->subscription) {
            return new \WP_Error(
                'ys_helcim_js_no_subscription',
                __('The Helcim payment method does not currently support subscription products. Please use a different payment method.', 'ys-helcim-via-fluentcart')
            );
        }

        // Currency guard: Helcim supports only USD / CAD (extendable via filter).
        $currency = strtoupper((string) $transaction->currency);
        if (!in_array($currency, $this->getSupportedCurrencies(), true)) {
            return new \WP_Error(
                'ys_helcim_js_currency_not_supported',
                sprintf(
                    /* translators: %s: currency code */
                    __('Helcim does not support the %s currency (USD and CAD only).', 'ys-helcim-via-fluentcart'),
                    $currency
                )
            );
        }

        // Amount guard: a zero-total order should not go through this gateway.
        if ((int) $transaction->total <= 0) {
            return new \WP_Error(
                'ys_helcim_js_invalid_amount',
                __('The payment amount is invalid.', 'ys-helcim-via-fluentcart')
            );
        }

        // Credential completeness (fail fast): js_token / api_token / js_secret_key must all be present.
        if (
            $this->settings->getJsToken() === ''
            || $this->settings->getApiToken() === ''
            || $this->settings->getJsSecretKey() === ''
        ) {
            YSHelcimLogger::error('helcim.js: credentials are incomplete, cannot create the payment', [
                'mode' => $this->settings->getMode(),
            ]);
            return new \WP_Error(
                'ys_helcim_js_missing_credentials',
                __('The Helcim credentials are incomplete. Please contact the site administrator.', 'ys-helcim-via-fluentcart')
            );
        }

        // The envelope matches ys_helcim (already E2E verified): it omits custom_payment_url —
        // that key would make FluentCart redirect to its built-in custom checkout page, so orderHandler()'s
        // resolved value would not include payment_data (leaving the front end unable to tokenize inline).
        return [
            'status'       => 'success',
            'nextAction'   => 'ys_helcim_js',
            'actionName'   => 'custom',
            'message'      => __('Your order has been created. Please complete the credit card payment.', 'ys-helcim-via-fluentcart'),
            // Custom data: what the front-end helcimProcess + confirm AJAX need (no secrets of any kind).
            'payment_data' => [
                'transaction_uuid' => $transaction->uuid,
                'confirm_nonce'    => wp_create_nonce('ys_helcim_fct_confirm_js'),
                'js_token'         => $this->settings->getJsToken(),
                'test_mode'        => $this->settings->getMode() === 'test',
            ],
        ];
    }

    /**
     * Checkout page payment-method info (called when the front end fetches paymentInfoUrl).
     *
     * Returns only the minimal data needed to render (mode and button text), with no secrets.
     *
     * @param array $data The request data.
     * @return void Ends with wp_send_json.
     */
    public function getOrderInfo(array $data)
    {
        // Block at load time when the currency does not match (so the customer does not fail only after filling in the card).
        if (!$this->isCurrencySupported()) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('Helcim does not support the current store currency (USD and CAD only).', 'ys-helcim-via-fluentcart'),
            ], 422);
        }

        wp_send_json([
            'status'       => 'success',
            'message'      => __('Payment information retrieved.', 'ys-helcim-via-fluentcart'),
            'data'         => [],
            'payment_args' => [
                'mode'        => $this->settings->getMode(),
                'button_text' => $this->settings->getCheckoutButtonText(),
            ],
        ], 200);
    }

    /**
     * Scripts to load on the checkout page.
     *
     * The SDK URL matches the official helcim.js used by the Woo version's YSHelcimJsGateway.
     *
     * @param string $hasSubscription Whether the cart contains a subscription product ('yes' / 'no').
     * @return array
     */
    public function getEnqueueScriptSrc($hasSubscription = 'no'): array
    {
        return [
            [
                'handle' => 'ys-helcim-js-sdk',
                'src'    => 'https://secure.helcim.app/js/helcim.js',
            ],
            [
                'handle'  => 'ys-helcim-js-checkout',
                'src'     => YS_HELCIM_FCT_URL . 'assets/js/ys-helcim-js-checkout.js',
                'deps'    => ['ys-helcim-js-sdk'],
                'version' => YS_HELCIM_FCT_VERSION,
            ],
        ];
    }

    /**
     * Styles to load on the checkout page (Code Review 🟡-2: the form styling must be self-contained when this gateway is enabled on its own).
     *
     * @return array
     */
    public function getEnqueueStyleSrc(): array
    {
        return [
            [
                'handle' => 'ys-helcim-checkout',
                'src'    => YS_HELCIM_FCT_URL . 'assets/css/ys-helcim-checkout.css',
            ],
        ];
    }

    /**
     * Front-end localize data (attached to the first enqueued handle).
     *
     * @return array
     */
    public function getLocalizeData(): array
    {
        return [
            'ys_helcim_js_fct_data' => [
                'ajax_url'       => admin_url('admin-ajax.php'),
                'confirm_action' => 'ys_helcim_fct_confirm_js',
                'translations'   => [
                    'card_number_label' => __('Card number', 'ys-helcim-via-fluentcart'),
                    'card_expiry_label' => __('Expiry (MM/YY)', 'ys-helcim-via-fluentcart'),
                    'card_cvv_label'    => __('Security code', 'ys-helcim-via-fluentcart'),
                    'pay_button'        => $this->settings->getCheckoutButtonText(),
                    'card_invalid'      => __('Please check that your card details are complete.', 'ys-helcim-via-fluentcart'),
                    'tokenize_failed'   => __('Card verification failed. Please check your card details and try again.', 'ys-helcim-via-fluentcart'),
                    'order_failed'      => __('The order could not be created. Please refresh the page and try again.', 'ys-helcim-via-fluentcart'),
                    'confirm_failed'    => __('We could not confirm your payment. Please try again shortly.', 'ys-helcim-via-fluentcart'),
                    'processing'        => __('Processing payment, please wait…', 'ys-helcim-via-fluentcart'),
                    'redirecting'       => __('Redirecting to the receipt page…', 'ys-helcim-via-fluentcart'),
                ],
            ],
        ];
    }

    /**
     * Admin settings fields (FluentCart tabs pattern).
     *
     * The secret fields (api_token / js_secret_key / webhook_verifier_token) are
     * password type and are encrypted by beforeSettingsUpdate() on save.
     *
     * @return array
     */
    public function fields(): array
    {
        $webhook_url = trailingslashit(site_url()) . '?fluent-cart=fct_payment_listener_ipn&method=ys_helcim_js';

        $notice_html = $this->renderStoreModeNotice()
            . '<div class="mt-5"><p>'
            . esc_html__('Helcim has no separate sandbox environment: to test, request a "developer test account" from Helcim and enter that credential set on the Test tab, using the official test card numbers.', 'ys-helcim-via-fluentcart')
            . '</p><p>'
            . esc_html__('Only USD / CAD are supported. The Helcim.js Configuration must be created as a Verify type in your Helcim dashboard (tokenization only; the charge is executed server-side).', 'ys-helcim-via-fluentcart')
            . '</p></div>';

        $webhook_html = '<div>'
            . '<p><b>' . esc_html__('Webhook URL:', 'ys-helcim-via-fluentcart') . '</b>'
            . '<code class="copyable-content">' . esc_html($webhook_url) . '</code></p>'
            . '<p>' . esc_html__('In your Helcim dashboard, go to All Tools → Integrations → Webhooks and set the URL above (must be HTTPS), then paste the generated Verifier Token into the field below. Webhook reconciliation is not enabled until the Verifier Token is set.', 'ys-helcim-via-fluentcart') . '</p>'
            . '</div>';

        $test_schema = [
            'test_api_token'     => [
                'value'       => '',
                'label'       => __('Test API Token', 'ys-helcim-via-fluentcart'),
                'type'        => 'password',
                'placeholder' => __('API token for your developer test account', 'ys-helcim-via-fluentcart'),
                'dependency'  => [
                    'depends_on' => 'payment_mode',
                    'operator'   => '=',
                    'value'      => 'test',
                ],
            ],
            'test_js_token'      => [
                'value'       => '',
                'label'       => __('Test Helcim.js Token', 'ys-helcim-via-fluentcart'),
                'type'        => 'text',
                'placeholder' => __('The Helcim.js Configuration Token (Verify type)', 'ys-helcim-via-fluentcart'),
                'dependency'  => [
                    'depends_on' => 'payment_mode',
                    'operator'   => '=',
                    'value'      => 'test',
                ],
            ],
            'test_js_secret_key' => [
                'value'       => '',
                'label'       => __('Test Helcim.js Secret Key', 'ys-helcim-via-fluentcart'),
                'type'        => 'password',
                'placeholder' => __('The Helcim.js Configuration Secret Key', 'ys-helcim-via-fluentcart'),
                'dependency'  => [
                    'depends_on' => 'payment_mode',
                    'operator'   => '=',
                    'value'      => 'test',
                ],
            ],
        ];

        $live_schema = [
            'live_api_token'     => [
                'value'       => '',
                'label'       => __('Live API Token', 'ys-helcim-via-fluentcart'),
                'type'        => 'password',
                'placeholder' => __('API token for your live account', 'ys-helcim-via-fluentcart'),
                'dependency'  => [
                    'depends_on' => 'payment_mode',
                    'operator'   => '=',
                    'value'      => 'live',
                ],
            ],
            'live_js_token'      => [
                'value'       => '',
                'label'       => __('Live Helcim.js Token', 'ys-helcim-via-fluentcart'),
                'type'        => 'text',
                'placeholder' => __('The Helcim.js Configuration Token (Verify type)', 'ys-helcim-via-fluentcart'),
                'dependency'  => [
                    'depends_on' => 'payment_mode',
                    'operator'   => '=',
                    'value'      => 'live',
                ],
            ],
            'live_js_secret_key' => [
                'value'       => '',
                'label'       => __('Live Helcim.js Secret Key', 'ys-helcim-via-fluentcart'),
                'type'        => 'password',
                'placeholder' => __('The Helcim.js Configuration Secret Key', 'ys-helcim-via-fluentcart'),
                'dependency'  => [
                    'depends_on' => 'payment_mode',
                    'operator'   => '=',
                    'value'      => 'live',
                ],
            ],
        ];

        // Note: is_active is not declared in fields() — the same is true of all four of FluentCart's built-in gateways.
        // The enable switch is provided externally by the admin payment methods list UI (sent into updateSettings with the settings save payload).
        return [
            'notice'                 => [
                'value' => $notice_html,
                'label' => __('Store mode notice', 'ys-helcim-via-fluentcart'),
                'type'  => 'notice',
            ],
            'payment_mode'           => [
                'type'   => 'tabs',
                'schema' => [
                    [
                        'type'   => 'tab',
                        'label'  => __('Live credentials', 'ys-helcim-via-fluentcart'),
                        'value'  => 'live',
                        'schema' => $live_schema,
                    ],
                    [
                        'type'   => 'tab',
                        'label'  => __('Test credentials', 'ys-helcim-via-fluentcart'),
                        'value'  => 'test',
                        'schema' => $test_schema,
                    ],
                ],
            ],
            'webhook_desc'           => [
                'value' => $webhook_html,
                'label' => __('Webhook settings', 'ys-helcim-via-fluentcart'),
                'type'  => 'html_attr',
            ],
            'webhook_verifier_token' => [
                'value'       => '',
                'label'       => __('Webhook Verifier Token', 'ys-helcim-via-fluentcart'),
                'type'        => 'password',
                'placeholder' => __('The Verifier Token from the Helcim Webhooks settings page', 'ys-helcim-via-fluentcart'),
            ],
            'checkout_button_text'   => [
                'value'       => '',
                'label'       => __('Checkout button text', 'ys-helcim-via-fluentcart'),
                'type'        => 'text',
                'placeholder' => __('Default: Pay with credit card', 'ys-helcim-via-fluentcart'),
            ],
            'debug_mode'             => [
                'value' => 'no',
                'label' => __('Enable debug logging (secret values are masked automatically)', 'ys-helcim-via-fluentcart'),
                'type'  => 'enable',
            ],
        ];
    }

    /**
     * Static validation before saving settings (runs when is_active = yes).
     *
     * Validates the completeness of the credential set for the store's current mode.
     *
     * @param array $data The form data.
     * @return array {status, message}
     */
    public static function validateSettings($data): array
    {
        $mode = (new StoreSettings())->get('order_mode') === 'test' ? 'test' : 'live';

        $mode_label = $mode === 'test'
            ? __('test', 'ys-helcim-via-fluentcart')
            : __('live', 'ys-helcim-via-fluentcart');

        if (empty($data[$mode . '_api_token'])) {
            return [
                'status'  => 'failed',
                /* translators: %s: mode name (test/live) */
                'message' => sprintf(__('Please enter the API token for %s mode.', 'ys-helcim-via-fluentcart'), $mode_label),
            ];
        }

        if (empty($data[$mode . '_js_token'])) {
            return [
                'status'  => 'failed',
                /* translators: %s: mode name (test/live) */
                'message' => sprintf(__('Please enter the Helcim.js Token for %s mode.', 'ys-helcim-via-fluentcart'), $mode_label),
            ];
        }

        if (empty($data[$mode . '_js_secret_key'])) {
            return [
                'status'  => 'failed',
                /* translators: %s: mode name (test/live) */
                'message' => sprintf(__('Please enter the Helcim.js Secret Key for %s mode.', 'ys-helcim-via-fluentcart'), $mode_label),
            ];
        }

        return [
            'status'  => 'success',
            'message' => __('Helcim.js settings verified.', 'ys-helcim-via-fluentcart'),
        ];
    }

    /**
     * Data processing before saving settings: encrypt the secret fields.
     *
     * Helper::encryptKey is idempotent (an already-encrypted value is returned
     * as-is), so it is safe to always encrypt the secret fields for both modes.
     *
     * @param array $data        The new settings.
     * @param array $oldSettings The previous settings.
     * @return array
     */
    public static function beforeSettingsUpdate($data, $oldSettings): array
    {
        $secret_fields = [
            'test_api_token',
            'live_api_token',
            'test_js_secret_key',
            'live_js_secret_key',
            'webhook_verifier_token',
        ];

        foreach ($secret_fields as $field) {
            if (!empty($data[$field]) && is_string($data[$field])) {
                $data[$field] = Helper::encryptKey($data[$field]);
            }
        }

        return $data;
    }

    /**
     * Refund (triggered in the admin, called by FluentCart's Refund service).
     *
     * POST /v2/payment/refund; on success returns the Helcim refund transactionId
     * (a string), and FluentCart writes it to the refund transaction's
     * vendor_charge_id.
     *
     * @param \FluentCart\App\Models\OrderTransaction $transaction The original charge transaction.
     * @param int                                     $amount      The refund amount (in cents).
     * @param array                                   $args        Additional arguments.
     * @return string|\WP_Error The refund transaction ID, or an error.
     */
    public function processRefund($transaction, $amount, $args)
    {
        $amount = (int) $amount;

        if ($amount <= 0) {
            return new \WP_Error(
                'ys_helcim_js_refund_invalid_amount',
                __('The refund amount is invalid.', 'ys-helcim-via-fluentcart')
            );
        }

        $vendor_charge_id = (int) $transaction->vendor_charge_id;
        if ($vendor_charge_id <= 0) {
            return new \WP_Error(
                'ys_helcim_js_refund_no_charge_id',
                __('The Helcim transaction ID could not be found, so a remote refund is not possible.', 'ys-helcim-via-fluentcart')
            );
        }

        // Mode guard: prevents refunding a test transaction with a live token (or vice versa).
        $transaction_mode = (string) $transaction->payment_mode;
        if ($transaction_mode !== '' && $transaction_mode !== $this->settings->getMode()) {
            return new \WP_Error(
                'ys_helcim_js_refund_mode_mismatch',
                __('The transaction mode does not match the current store mode. Please switch the store order mode before refunding.', 'ys-helcim-via-fluentcart')
            );
        }

        $api_token = $this->settings->getApiToken();
        if ($api_token === '') {
            return new \WP_Error(
                'ys_helcim_js_refund_no_api_token',
                __('The Helcim API token has not been configured, so refunds are not possible.', 'ys-helcim-via-fluentcart')
            );
        }

        $payload = [
            'originalTransactionId' => $vendor_charge_id,
            'amount'                => number_format($amount / 100, 2, '.', ''),
            'ipAddress'             => (new YSHelcimJsProcessor($this->settings))->getClientIp(),
        ];

        // Idempotency key (deterministic, security review M2): bound to the original transaction + amount + existing refund count.
        // A "blind retry after a lost response" produces the same key (Helcim deduplicates naturally on its end);
        // a legitimate second partial refund of the same amount gets a new key because the previous one already wrote a refund transaction (count + 1).
        $refund_seq      = OrderTransaction::query()
            ->where('order_id', $transaction->order_id)
            ->where('transaction_type', Status::TRANSACTION_TYPE_REFUND)
            ->count();
        $idempotency_key = substr('yshfct-rf-' . $vendor_charge_id . '-' . $amount . '-' . $refund_seq, 0, 36);

        $response = YSHelcimApiClient::request('payment/refund', $payload, $api_token, $idempotency_key);

        if (is_wp_error($response)) {
            YSHelcimLogger::error('helcim.js: refund API failed', [
                'transaction_uuid' => $transaction->uuid,
                'error'            => $response->get_error_message(),
            ]);
            return $response;
        }

        if ('APPROVED' !== strtoupper((string) ($response['status'] ?? '')) || empty($response['transactionId'])) {
            $helcim_message = sanitize_text_field((string) ($response['responseMessage'] ?? ''));

            YSHelcimLogger::error('helcim.js: refund not approved', [
                'transaction_uuid' => $transaction->uuid,
                'helcim_message'   => $helcim_message,
            ]);

            return new \WP_Error(
                'ys_helcim_js_refund_failed',
                $helcim_message !== ''
                    ? sprintf(
                        /* translators: %s: the message returned by Helcim */
                        __('Helcim did not approve the refund: %s', 'ys-helcim-via-fluentcart'),
                        $helcim_message
                    )
                    : __('Helcim did not approve the refund.', 'ys-helcim-via-fluentcart')
            );
        }

        YSHelcimLogger::info('helcim.js: refund succeeded', [
            'transaction_uuid' => $transaction->uuid,
            'refund_id'        => (string) $response['transactionId'],
        ]);

        return (string) $response['transactionId'];
    }

    /**
     * Webhook (IPN) handler.
     *
     * URL: {site}/?fluent-cart=fct_payment_listener_ipn&method=ys_helcim_js
     *
     * Flow (fail-closed):
     * 1. No verifier token set → 501 (log it; the feature is not enabled).
     * 2. Signature verification failed → 401.
     * 3. type === 'cardTransaction' → GET card-transactions/{id} to verify.
     * 4. Delegate reconciliation to the Processor (invoiceNumber = order uuid confirms the pending transaction).
     *
     * @return void Ends with wp_send_json.
     */
    public function handleIPN(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_send_json(['message' => 'Method not allowed'], 405);
        }

        // 1. No verifier token set → return 501 explicitly (the webhook feature is not enabled).
        $verifier_token = $this->settings->getWebhookVerifierToken();
        if ($verifier_token === '') {
            YSHelcimLogger::error('helcim.js webhook: verifier token not set, refusing to process');
            wp_send_json([
                'message' => __('The webhook verifier token has not been configured.', 'ys-helcim-via-fluentcart'),
            ], 501);
        }

        // 2. Verify the signature (the raw body must stay verbatim, untouched by any processing).
        $raw_body = (string) file_get_contents('php://input');

        // Resource guard before verification: a Helcim webhook body is tiny, so reject anything over 1 MB outright.
        if (strlen($raw_body) > 1048576) {
            wp_send_json(['message' => 'payload too large'], 400);
        }

        $headers = $this->getRequestHeaders();

        if (!YSHelcimWebhookVerifier::verify($headers, $raw_body, $verifier_token)) {
            YSHelcimLogger::error('helcim.js webhook: signature verification failed');
            wp_send_json([
                'message' => __('Webhook signature verification failed.', 'ys-helcim-via-fluentcart'),
            ], 401);
        }

        // 3. Only handle cardTransaction events.
        $payload = json_decode($raw_body, true);
        if (!is_array($payload) || ($payload['type'] ?? '') !== 'cardTransaction') {
            wp_send_json(['message' => 'ignored'], 200);
        }

        $helcim_id = preg_replace('/[^0-9]/', '', (string) ($payload['id'] ?? ''));
        if ($helcim_id === '') {
            wp_send_json(['message' => 'ignored: invalid id'], 200);
        }

        $api_token = $this->settings->getApiToken();
        if ($api_token === '') {
            YSHelcimLogger::error('helcim.js webhook: API token not set, cannot verify the transaction');
            wp_send_json(['message' => 'api token missing'], 500);
        }

        // Verify the transaction contents via the Helcim API (the webhook body only carries an id and cannot be trusted directly).
        $helcim_tx = YSHelcimApiClient::request('card-transactions/' . $helcim_id, [], $api_token, null, 'GET');

        if (is_wp_error($helcim_tx) || !is_array($helcim_tx)) {
            YSHelcimLogger::error('helcim.js webhook: transaction verification failed', [
                'helcim_id' => $helcim_id,
                'error'     => is_wp_error($helcim_tx) ? $helcim_tx->get_error_message() : 'invalid response',
            ]);
            wp_send_json(['message' => 'lookup failed'], 500);
        }

        // 4. Reconcile (fail-closed: the amount/currency check runs inside the Processor).
        $result = (new YSHelcimJsProcessor($this->settings))->reconcileCardTransaction($helcim_tx);

        wp_send_json(['message' => $result['message']], $result['code']);
    }

    /**
     * Whether the current store currency is supported (used to decide checkout availability).
     *
     * @return bool
     */
    public function isCurrencySupported(): bool
    {
        $currency = strtoupper((string) CurrencySettings::get('currency'));

        return in_array($currency, $this->getSupportedCurrencies(), true);
    }

    /**
     * Get the list of supported currencies (extendable via filter).
     *
     * @return array Uppercase currency codes.
     */
    public function getSupportedCurrencies(): array
    {
        /**
         * Filter the list of currencies Helcim supports.
         *
         * @param array $currencies Defaults to ['USD', 'CAD'].
         */
        $currencies = apply_filters('ys_helcim_fct_supported_currencies', ['USD', 'CAD']);

        if (!is_array($currencies) || empty($currencies)) {
            $currencies = ['USD', 'CAD'];
        }

        return array_map('strtoupper', array_map('strval', $currencies));
    }

    /**
     * Get the request headers (compatible across server environments).
     *
     * getallheaders() may not exist on non-Apache environments, so fall back to
     * rebuilding from the $_SERVER HTTP_* keys. Signature-related header values
     * are kept verbatim for the HMAC comparison (the Verifier only does
     * hash_equals, so there is no injection risk).
     *
     * @return array
     */
    private function getRequestHeaders(): array
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                return $headers;
            }
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0 && is_scalar($value)) {
                $name           = str_replace('_', '-', substr($key, 5));
                $headers[$name] = (string) $value;
            }
        }

        return $headers;
    }
}
