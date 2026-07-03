<?php
/**
 * YS HelcimPay Settings — settings layer for the ys_helcim gateway.
 *
 * Storage location: the fct_meta table, meta_key =
 * fluent_cart_payment_settings_ys_helcim (loaded uniformly by FluentCart's
 * BaseGatewaySettings).
 *
 * Secret fields (API token / webhook verifier token) are stored encrypted using
 * FluentCart's Helper::encryptKey / decryptKey (encryption happens in
 * Gateway::beforeSettingsUpdate, matching the Stripe approach).
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\HelcimPay;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HelcimPay.js gateway settings.
 *
 * The test/live mode is decided by FluentCart's store-wide "order_mode" (the
 * Stripe pattern); each mode stores its own API token.
 */
class YSHelcimPaySettings extends BaseGatewaySettings {

	/**
	 * Settings values (loaded and merged with defaults by the BaseGatewaySettings constructor).
	 *
	 * @var array
	 */
	public $settings;

	/**
	 * FluentCart settings storage key (fct_meta.meta_key).
	 *
	 * @var string
	 */
	public $methodHandler = 'fluent_cart_payment_settings_ys_helcim';

	/**
	 * Default settings values.
	 *
	 * Note: payment_mode only remembers the currently selected tab on the admin
	 * settings page; the actual processing mode always follows the store's
	 * order_mode (getMode()).
	 *
	 * @return array
	 */
	public static function getDefaults() {
		return array(
			'is_active'              => 'no',
			'payment_mode'           => 'live',
			'live_api_token'         => '',
			'test_api_token'         => '',
			'webhook_verifier_token' => '',
			'checkout_button_text'   => '',
			'debug_mode'             => 'no',
		);
	}

	/**
	 * Whether this payment method is enabled.
	 *
	 * @return bool
	 */
	public function isActive(): bool {
		return ( $this->settings['is_active'] ?? 'no' ) === 'yes';
	}

	/**
	 * Get a settings value.
	 *
	 * @param string $key The settings key; leave empty to return all settings.
	 * @return mixed
	 */
	public function get( $key = '' ) {
		if ( '' !== $key && isset( $this->settings[ $key ] ) ) {
			return $this->settings[ $key ];
		}

		// A specific key was requested but does not exist → return an empty string (never return the whole settings bundle, which holds ciphertext).
		if ( '' !== $key ) {
			return '';
		}

		return $this->settings;
	}

	/**
	 * Get the current processing mode (test / live).
	 *
	 * Matches Stripe: driven by FluentCart's store-wide order_mode.
	 *
	 * @return string 'test' or 'live'.
	 */
	public function getMode() {
		return ( new StoreSettings() )->get( 'order_mode' );
	}

	/**
	 * Get the Helcim API token for the current mode (decrypted).
	 *
	 * @return string The decrypted token; an empty string if unset or decryption fails.
	 */
	public function getApiToken(): string {
		$key       = 'test' === $this->getMode() ? 'test_api_token' : 'live_api_token';
		$decrypted = Helper::decryptKey( (string) $this->get( $key ) );

		// decryptKey returns false for corrupt ciphertext — coerce to an empty string (fail-closed).
		return is_string( $decrypted ) ? $decrypted : '';
	}

	/**
	 * Get the webhook verifier token (decrypted; the base64 string from the Helcim dashboard).
	 *
	 * @return string The decrypted token; an empty string if unset or decryption fails.
	 */
	public function getWebhookVerifierToken(): string {
		$decrypted = Helper::decryptKey( (string) $this->get( 'webhook_verifier_token' ) );

		return is_string( $decrypted ) ? $decrypted : '';
	}

	/**
	 * Whether debug logging is enabled.
	 *
	 * @return bool
	 */
	public function isDebugMode(): bool {
		return 'yes' === $this->get( 'debug_mode' );
	}

	/**
	 * Get the checkout button text (returns a default when unset).
	 *
	 * @return string
	 */
	public function getCheckoutButtonText(): string {
		$text = trim( (string) $this->get( 'checkout_button_text' ) );

		if ( '' !== $text ) {
			return $text;
		}

		return __( 'Pay with credit card (Helcim)', 'ys-helcim-via-fluentcart' );
	}
}
