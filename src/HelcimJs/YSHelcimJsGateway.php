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
 *    returns payment_data (transaction_uuid / confirm_nonce / js_token).
 * 4. The front end runs helcimProcess() to tokenize and obtain a cardToken.
 * 5. AJAX ys_helcim_fct_confirm_js → YSHelcimJsProcessor charges server-side (fail-closed).
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\HelcimJs;

use FluentCart\Api\CurrencySettings;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Services\Payments\PaymentInstance;
use YangSheep\Helcim\FluentCart\HelcimPay\YSHelcimPayRecoveryCapability;
use YangSheep\Helcim\FluentCart\Settings\YSHelcimSecretStorage;
use YangSheep\Helcim\FluentCart\Support\YSHelcimLogger;
use YangSheep\Helcim\FluentCart\Webhook\YSHelcimWebhookDeliveryUrl;
use YangSheep\Helcim\FluentCart\YSHelcimFctBootstrap;

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
    public array $supportedFeatures = ['payment', 'webhook', 'custom_payment'];

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
            'status'             => $this->isEnabled(),
            'upcoming'           => false,
            'supported_features' => $this->supportedFeatures,
        ];
    }

    /** Keep invalid or unverifiable permanent credentials out of checkout. */
    public function isEnabled(): bool
    {
        return $this->settings->get('is_active') === 'yes' && $this->hasCompleteRuntimeCredentials();
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
        if (!$this->hasCompleteRuntimeCredentials()) {
            YSHelcimLogger::error('helcim.js: credentials are incomplete, cannot create the payment', [
                'mode' => $this->settings->getMode(),
            ]);
            return new \WP_Error(
                'ys_helcim_js_missing_credentials',
                __('The Helcim credentials are incomplete. Please contact the site administrator.', 'ys-helcim-via-fluentcart')
            );
        }
        if (!$this->hasDurableRecoverySchedule()) {
            YSHelcimLogger::error('helcim.js: durable purchase recovery schedule is unavailable');
            return $this->recoveryUnavailableError();
        }
        $recoveryAccess = $this->verifyRecoveryApiAccess();
        if (is_wp_error($recoveryAccess)) {
            YSHelcimLogger::error('helcim.js: card-transaction recovery permission is unavailable');
            return $recoveryAccess;
        }

        $confirmToken = (new YSHelcimPurchaseConfirmationToken())->issue(
            (string) $transaction->uuid,
            (int) $transaction->id
        );
        if (is_wp_error($confirmToken)) {
            return $confirmToken;
        }

        $billingAddress = $order->billing_address ?? null;
        $cardholderAddress = is_object($billingAddress)
            ? trim((string) ($billingAddress->address_1 ?? ''))
            : '';
        $cardholderPostalCode = is_object($billingAddress)
            ? trim((string) ($billingAddress->postcode ?? ''))
            : '';

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
                'confirm_token'    => $confirmToken,
                'js_token'         => $this->settings->getJsToken(),
                'cardholder_address' => $cardholderAddress,
                'cardholder_postal_code' => $cardholderPostalCode,
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

        // This endpoint runs before orderHandler(). Refuse to render card entry
        // unless both charge and recovery credentials are ready, so a broken
        // configuration cannot create stranded pending orders.
        if (!$this->hasCompleteRuntimeCredentials()) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('The Helcim payment method is not fully configured. Please contact the site administrator.', 'ys-helcim-via-fluentcart'),
            ], 503);
        }
        if (!$this->hasDurableRecoverySchedule()) {
            $error = $this->recoveryUnavailableError();
            wp_send_json([
                'status'  => 'failed',
                'message' => $error->get_error_message(),
            ], 503);
        }
        $recoveryAccess = $this->verifyRecoveryApiAccess();
        if (is_wp_error($recoveryAccess)) {
            wp_send_json([
                'status'  => 'failed',
                'message' => $recoveryAccess->get_error_message(),
            ], 503);
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
                'src'    => 'https://secure.myhelcim.com/js/version2.js',
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
                    'button_text'       => $this->settings->getCheckoutButtonText(),
                    'loading'           => __('Loading payment module…', 'ys-helcim-via-fluentcart'),
                    'init_failed'       => __('The payment module failed to load. Please refresh the page and try again.', 'ys-helcim-via-fluentcart'),
                    'order_failed'      => __('The order could not be created. Please refresh the page and try again.', 'ys-helcim-via-fluentcart'),
                    'no_token'          => __('The payment settings could not be loaded. Please refresh the page and try again.', 'ys-helcim-via-fluentcart'),
                    'sdk_missing'       => __('The payment component has not loaded. Please refresh the page and try again.', 'ys-helcim-via-fluentcart'),
                    'card_number_label' => __('Card number', 'ys-helcim-via-fluentcart'),
                    'card_expiry_label' => __('Expiry (MM/YY)', 'ys-helcim-via-fluentcart'),
                    'card_cvv_label'    => __('Security code', 'ys-helcim-via-fluentcart'),
                    'card_name_label'   => __('Cardholder name', 'ys-helcim-via-fluentcart'),
                    'card_number_invalid' => __('Please enter a valid card number.', 'ys-helcim-via-fluentcart'),
                    'card_expiry_invalid' => __('Please enter a valid expiry date (MM/YY).', 'ys-helcim-via-fluentcart'),
                    'card_cvv_invalid'  => __('Please enter a valid security code.', 'ys-helcim-via-fluentcart'),
                    'processing_card'   => __('Processing your card details…', 'ys-helcim-via-fluentcart'),
                    'confirming'        => __('Confirming your payment…', 'ys-helcim-via-fluentcart'),
                    'redirecting'       => __('Redirecting to the receipt page…', 'ys-helcim-via-fluentcart'),
                    'tokenize_failed_prefix' => __('Payment failed: ', 'ys-helcim-via-fluentcart'),
                    'tokenize_failed'   => __('Card verification failed. Please check your card details and try again.', 'ys-helcim-via-fluentcart'),
                    'timeout'           => __('The payment timed out. To prevent an incorrect charge, refresh the page before trying again.', 'ys-helcim-via-fluentcart'),
                    'confirm_failed'    => __('We could not confirm your payment. Please contact the store for help.', 'ys-helcim-via-fluentcart'),
                    'network_error'     => __('The payment result could not be confirmed. To prevent a duplicate charge, refresh the page or contact the store before trying again.', 'ys-helcim-via-fluentcart'),
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
        $webhook_url = YSHelcimWebhookDeliveryUrl::validate(rest_url('ys-fc-pay/v1/events/card'));

        $notice_html = $this->renderStoreModeNotice()
            . '<div class="mt-5"><p>'
            . esc_html__('Helcim has no separate sandbox environment: to test, request a "developer test account" from Helcim and enter that credential set on the Test tab, using the official test card numbers.', 'ys-helcim-via-fluentcart')
            . '</p><p>'
            . esc_html__('Only USD / CAD are supported. The Helcim.js Configuration must be Active and created as Card Verify with "Include XML on Response" enabled (tokenization only; the charge is executed server-side).', 'ys-helcim-via-fluentcart')
            . '</p><p>'
            . esc_html__('For a Developer Test Account, add this checkout site\'s exact HTTPS origin under Website URLs. Legacy Helcim.js Test Mode must stay off, and the checkout form must not send test=1.', 'ys-helcim-via-fluentcart')
            . '</p><p>'
            . esc_html__('The matching API Access token must grant Transaction Processing Admin so the server can purchase, refund, and reverse test transactions.', 'ys-helcim-via-fluentcart')
            . '</p></div>';

        $webhook_html = is_wp_error($webhook_url)
            ? '<div class="notice notice-error inline"><p>' . esc_html($webhook_url->get_error_message()) . '</p></div>'
            : '<div>'
            . '<p><b>' . esc_html__('Webhook URL:', 'ys-helcim-via-fluentcart') . '</b>'
            . '<code class="copyable-content">' . esc_html($webhook_url) . '</code></p>'
            . '<p>' . esc_html__('In your Helcim dashboard, go to All Tools → Integrations → Webhooks and set the URL above (must be HTTPS), then paste that account\'s Verifier Token into the matching credential tab. Webhook reconciliation is not enabled until the Verifier Token is set.', 'ys-helcim-via-fluentcart') . '</p>'
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
                'placeholder' => __('The Helcim.js Configuration Token (Verify type, Include XML enabled)', 'ys-helcim-via-fluentcart'),
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
            'test_webhook_verifier_token' => [
                'value'       => '',
                'label'       => __('Test Webhook Verifier Token', 'ys-helcim-via-fluentcart'),
                'type'        => 'password',
                'placeholder' => __('Verifier Token for the developer test account webhook', 'ys-helcim-via-fluentcart'),
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
                'placeholder' => __('The Helcim.js Configuration Token (Verify type, Include XML enabled)', 'ys-helcim-via-fluentcart'),
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
            'live_webhook_verifier_token' => [
                'value'       => '',
                'label'       => __('Live Webhook Verifier Token', 'ys-helcim-via-fluentcart'),
                'type'        => 'password',
                'placeholder' => __('Verifier Token for the live Helcim webhook', 'ys-helcim-via-fluentcart'),
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

        $settings = new YSHelcimJsSettings();
        $submittedOrStored = static function (string $field, string $stored) use ($data): string {
            $submitted = isset($data[$field]) && is_scalar($data[$field])
                ? trim((string) $data[$field])
                : '';
            return $submitted !== '' ? $submitted : trim($stored);
        };

        $apiTokenAvailable = YSHelcimSecretStorage::isAvailableForValidation(
            array_key_exists($mode . '_api_token', (array) $data) ? $data[$mode . '_api_token'] : null,
            $settings->getApiTokenForMode($mode)
        );
        $jsToken = $submittedOrStored($mode . '_js_token', $settings->getJsToken());
        $jsSecretAvailable = YSHelcimSecretStorage::isAvailableForValidation(
            array_key_exists($mode . '_js_secret_key', (array) $data) ? $data[$mode . '_js_secret_key'] : null,
            $settings->getJsSecretKeyForMode($mode)
        );
        $verifierAvailable = YSHelcimSecretStorage::isAvailableForValidation(
            array_key_exists($mode . '_webhook_verifier_token', (array) $data) ? $data[$mode . '_webhook_verifier_token'] : null,
            $settings->getWebhookVerifierTokenForMode($mode)
        );

        if (!$apiTokenAvailable) {
            return [
                'status'  => 'failed',
                /* translators: %s: mode name (test/live) */
                'message' => sprintf(__('Please enter the API token for %s mode.', 'ys-helcim-via-fluentcart'), $mode_label),
            ];
        }

        if ($jsToken === '') {
            return [
                'status'  => 'failed',
                /* translators: %s: mode name (test/live) */
                'message' => sprintf(__('Please enter the Helcim.js Token for %s mode.', 'ys-helcim-via-fluentcart'), $mode_label),
            ];
        }

        if (!$jsSecretAvailable) {
            return [
                'status'  => 'failed',
                /* translators: %s: mode name (test/live) */
                'message' => sprintf(__('Please enter the Helcim.js Secret Key for %s mode.', 'ys-helcim-via-fluentcart'), $mode_label),
            ];
        }

        if (!$verifierAvailable) {
            return [
                'status'  => 'failed',
                /* translators: %s: mode name (test/live) */
                'message' => sprintf(__('Please enter the Webhook Verifier Token for %s mode.', 'ys-helcim-via-fluentcart'), $mode_label),
            ];
        }

        return [
            'status'  => 'success',
            'message' => __('Helcim.js credential fields are present. Complete a browser payment test to verify the Helcim.js Configuration, Website URLs, and paired Secret Key.', 'ys-helcim-via-fluentcart'),
        ];
    }

    /**
     * Data processing before saving settings: persist only proven ciphertext.
     *
     * Blank fields preserve verified old ciphertext. A new value that cannot be
     * encrypted and independently verified is discarded and disables the gateway.
     *
     * @param array $data        The new settings.
     * @param array $oldSettings The previous settings.
     * @return array
     */
    public static function beforeSettingsUpdate($data, $oldSettings): array
    {
        $persistenceFailed = false;
        $secret_fields = [
            'test_api_token',
            'live_api_token',
            'test_js_secret_key',
            'live_js_secret_key',
            'test_webhook_verifier_token',
            'live_webhook_verifier_token',
        ];

        foreach ($secret_fields as $field) {
            $data[$field] = YSHelcimSecretStorage::prepareForStorage(
                array_key_exists($field, (array) $data) ? $data[$field] : null,
                array_key_exists($field, (array) $oldSettings) ? $oldSettings[$field] : null,
                $persistenceFailed
            );
        }

        if ($persistenceFailed) {
            $data['is_active'] = 'no';
        }

        // Legacy values are bound once during bootstrap migration. Settings
        // saves may clear stale input, but must never reinterpret its mode.
        $data['webhook_verifier_token'] = '';

        return $data;
    }

    /**
     * Fail-closed guard for FluentCart's unsafe local-first refund flow.
     *
     * @param \FluentCart\App\Models\OrderTransaction $transaction The original charge transaction.
     * @param int                                     $amount      The refund amount (in cents).
     * @param array                                   $args        Additional arguments.
     * @return \WP_Error Always directs administrators to the remote-first flow.
     */
    public function processRefund($transaction, $amount, $args)
    {
        unset($transaction, $amount, $args);

        return new \WP_Error(
            'ys_helcim_native_refund_disabled',
            __('Use the Helcim Operations refund panel. FluentCart\'s native refund flow is disabled because it records a refund before the provider confirms it.', 'ys-helcim-via-fluentcart')
        );
    }

    /**
     * Retired FluentCart IPN compatibility route.
     *
     * Verification and reconciliation belong exclusively to the clean REST route.
     * This handler performs no provider lookup and no local payment mutation.
     *
     * @return void Ends with wp_send_json.
     */
    public function handleIPN(): void
    {
        wp_send_json([
            'message'     => __('This legacy webhook listener is retired. Configure Helcim to use the REST webhook URL.', 'ys-helcim-via-fluentcart'),
            'webhook_url' => rest_url('ys-fc-pay/v1/events/card'),
        ], 410);
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
     * Get the currencies implemented by the durable purchase contract.
     *
     * @return array Uppercase currency codes.
     */
    public function getSupportedCurrencies(): array
    {
        return ['USD', 'CAD'];
    }

    /** Require both charge credentials and the signed-webhook recovery key. */
    private function hasCompleteRuntimeCredentials(): bool
    {
        return $this->hasCompleteRecoveryCredentials()
            && '' !== trim($this->settings->getJsToken())
            && '' !== trim($this->settings->getJsSecretKey());
    }

    /** Require the charge token and signed-webhook recovery token for the current store mode. */
    private function hasCompleteRecoveryCredentials(): bool
    {
        return '' !== trim($this->settings->getApiToken())
            && '' !== trim($this->settings->getWebhookVerifierToken());
    }

    /** Inline checkout must never start without its durable lost-response worker. */
    protected function hasDurableRecoverySchedule(): bool
    {
        return YSHelcimFctBootstrap::init()->ensureHostedPurchaseReconciliation();
    }

    /** @return true|\WP_Error */
    protected function verifyRecoveryApiAccess()
    {
        return (new YSHelcimPayRecoveryCapability())->verify($this->settings);
    }

    private function recoveryUnavailableError(): \WP_Error
    {
        return new \WP_Error(
            'ys_helcim_js_recovery_unavailable',
            __('Inline payment recovery is unavailable. Please contact the site administrator.', 'ys-helcim-via-fluentcart')
        );
    }

}
