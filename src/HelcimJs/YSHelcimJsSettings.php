<?php
/**
 * YS Helcim.js Gateway settings (FluentCart).
 *
 * Settings access layer for the helcim.js inline card form payment method.
 * Settings are stored under the WP meta key
 * `fluent_cart_payment_settings_ys_helcim_js`. Secret fields (api_token /
 * js_secret_key / webhook_verifier_token) are stored encrypted with FluentCart's
 * `Helper::encryptKey` (in Gateway::beforeSettingsUpdate) and decrypted on read
 * with `Helper::decryptKey` (matching the Stripe / PayPal approach).
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\HelcimJs;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class YSHelcimJsSettings
 *
 * The settings object for the helcim.js gateway (slug: ys_helcim_js). The test
 * and live credential sets are stored independently; which set is actually used
 * is decided by FluentCart's store-wide mode (StoreSettings order_mode).
 */
class YSHelcimJsSettings extends BaseGatewaySettings
{
    /**
     * @var array The currently effective settings values (merged with defaults).
     */
    public $settings;

    /**
     * @var string The FluentCart settings storage meta key.
     */
    public $methodHandler = 'fluent_cart_payment_settings_ys_helcim_js';

    /**
     * Default settings values.
     *
     * The secret fields (*_api_token / *_js_secret_key / webhook_verifier_token)
     * are stored as ciphertext and default to an empty string.
     *
     * @return array
     */
    public static function getDefaults(): array
    {
        return [
            'is_active'              => 'no',
            // UI tab state (only for the admin settings page tabs; the actual mode is from getMode()).
            'payment_mode'           => 'live',
            // Test credentials (Helcim developer test account).
            'test_api_token'         => '',
            'test_js_token'          => '',
            'test_js_secret_key'     => '',
            // Live credentials.
            'live_api_token'         => '',
            'live_js_token'          => '',
            'live_js_secret_key'     => '',
            // Webhook signing token (provided on the Helcim dashboard Webhooks page, base64).
            'webhook_verifier_token' => '',
            // Checkout button text (a default is used when empty).
            'checkout_button_text'   => '',
            // Debug logging switch.
            'debug_mode'             => 'no',
        ];
    }

    /**
     * Get a settings value.
     *
     * @param string $key The settings key; an empty string returns all settings.
     * @return mixed
     */
    public function get($key = '')
    {
        if ($key !== '' && isset($this->settings[$key])) {
            return $this->settings[$key];
        }

        if ($key !== '') {
            return '';
        }

        return $this->settings;
    }

    /**
     * Whether this payment method is enabled.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->get('is_active') === 'yes';
    }

    /**
     * Get the current mode (test / live).
     *
     * Matches the Stripe pattern: decided by FluentCart's store-wide "order mode"
     * rather than the gateway's own setting.
     *
     * @return string 'test' or 'live'.
     */
    public function getMode()
    {
        return (new StoreSettings())->get('order_mode');
    }

    /**
     * Get the field prefix for the current mode ('test_' or 'live_').
     *
     * @return string
     */
    private function getModePrefix(): string
    {
        return $this->getMode() === 'test' ? 'test_' : 'live_';
    }

    /**
     * Get the Helcim API token (for the current mode; decrypted).
     *
     * Used by the v2 APIs such as payment/purchase, payment/refund, and card-transactions.
     *
     * @return string
     */
    public function getApiToken(): string
    {
        return (string) Helper::decryptKey($this->get($this->getModePrefix() . 'api_token'));
    }

    /**
     * Get the Helcim.js Configuration Token (for the current mode).
     *
     * This is the public token used for front-end tokenization (it is sent to the
     * browser) and is not stored encrypted.
     *
     * @return string
     */
    public function getJsToken(): string
    {
        return (string) $this->get($this->getModePrefix() . 'js_token');
    }

    /**
     * Get the Helcim.js Secret Key (for the current mode; decrypted).
     *
     * Used to verify the helcim.js response hash (validateResponseHash). It is a
     * secret for server-side use only and must never be sent to the front end or
     * written to the log.
     *
     * @return string
     */
    public function getJsSecretKey(): string
    {
        return (string) Helper::decryptKey($this->get($this->getModePrefix() . 'js_secret_key'));
    }

    /**
     * Get the webhook verifier token (decrypted; a base64 string).
     *
     * Provided on the Helcim dashboard Webhooks page and used for HMAC-SHA256 verification.
     *
     * @return string
     */
    public function getWebhookVerifierToken(): string
    {
        return (string) Helper::decryptKey($this->get('webhook_verifier_token'));
    }

    /**
     * Whether debug logging is enabled.
     *
     * @return bool
     */
    public function isDebugMode(): bool
    {
        return $this->get('debug_mode') === 'yes';
    }

    /**
     * Get the checkout button text (returns a default when unset).
     *
     * @return string
     */
    public function getCheckoutButtonText(): string
    {
        $text = trim((string) $this->get('checkout_button_text'));

        if ($text !== '') {
            return $text;
        }

        return __('Pay with credit card', 'ys-helcim-via-fluentcart');
    }
}
