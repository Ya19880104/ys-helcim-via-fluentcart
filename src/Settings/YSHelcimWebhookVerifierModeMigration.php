<?php
/**
 * One-time migration for the historical account-global webhook verifier.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Settings;

use FluentCart\Api\StoreSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Binds each legacy verifier to the store mode observed when migration starts.
 */
final class YSHelcimWebhookVerifierModeMigration {

	public const VERSION = '1';

	public const OPTION_NAME = 'ys_helcim_webhook_verifier_mode_migration';

	private const SETTINGS_KEYS = array(
		'fluent_cart_payment_settings_ys_helcim',
		'fluent_cart_payment_settings_ys_helcim_js',
	);

	/**
	 * Migrate both gateway settings rows, recording the selected mode before any
	 * row write so a retry cannot follow a later store-mode switch.
	 */
	public static function maybeMigrate(): bool {
		if (
			! function_exists( 'fluent_cart_get_option' ) ||
			! function_exists( 'fluent_cart_update_option' )
		) {
			return false;
		}

		$state = get_option( self::OPTION_NAME, null );
		if ( self::isCompleteState( $state ) ) {
			return true;
		}

		if ( ! self::isPendingState( $state ) ) {
			$mode = self::normalizeMode( (string) ( new StoreSettings() )->get( 'order_mode' ) );
			if ( '' === $mode ) {
				return false;
			}

			add_option(
				self::OPTION_NAME,
				array(
					'version' => self::VERSION,
					'mode'    => $mode,
					'status'  => 'pending',
				),
				'',
				false
			);
			$state = get_option( self::OPTION_NAME, null );
		}

		if ( ! self::isPendingState( $state ) ) {
			return false;
		}
		$mode = (string) $state['mode'];

		try {
			foreach ( self::SETTINGS_KEYS as $settings_key ) {
				$settings = fluent_cart_get_option( $settings_key, array(), false );
				if ( ! is_array( $settings ) ) {
					return false;
				}

				$migrated = self::bindLegacyVerifier( $settings, $mode );
				if ( $migrated === $settings ) {
					continue;
				}

				$result = fluent_cart_update_option( $settings_key, $migrated );
				if ( false === $result || is_wp_error( $result ) ) {
					return false;
				}
			}
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return false;
		}

		$complete = array(
			'version' => self::VERSION,
			'mode'    => $mode,
			'status'  => 'complete',
		);
		update_option( self::OPTION_NAME, $complete, false );

		return $complete === get_option( self::OPTION_NAME, null );
	}

	/** @param array<string,mixed> $settings @return array<string,mixed> */
	private static function bindLegacyVerifier( array $settings, string $mode ): array {
		$legacy = isset( $settings['webhook_verifier_token'] ) && is_string( $settings['webhook_verifier_token'] )
			? $settings['webhook_verifier_token']
			: '';
		if ( '' === $legacy ) {
			return $settings;
		}

		$mode_key = $mode . '_webhook_verifier_token';
		$current  = isset( $settings[ $mode_key ] ) && is_string( $settings[ $mode_key ] )
			? $settings[ $mode_key ]
			: '';
		if ( '' === $current ) {
			$settings[ $mode_key ] = $legacy;
		}
		$settings['webhook_verifier_token'] = '';

		return $settings;
	}

	private static function isPendingState( mixed $state ): bool {
		return self::isState( $state, 'pending' );
	}

	private static function isCompleteState( mixed $state ): bool {
		return self::isState( $state, 'complete' );
	}

	private static function isState( mixed $state, string $status ): bool {
		return is_array( $state ) &&
			self::VERSION === (string) ( $state['version'] ?? '' ) &&
			'' !== self::normalizeMode( (string) ( $state['mode'] ?? '' ) ) &&
			$status === (string) ( $state['status'] ?? '' );
	}

	private static function normalizeMode( string $mode ): string {
		$mode = strtolower( trim( $mode ) );
		return in_array( $mode, array( 'test', 'live' ), true ) ? $mode : '';
	}
}
