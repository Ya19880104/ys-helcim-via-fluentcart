<?php
/**
 * YS Helcim API Client — HTTP layer for the Helcim API v2.
 *
 * Centralizes Helcim API calls: authentication headers, idempotency keys,
 * timeouts, JSON encoding/decoding, error conversion (WP_Error), and masked
 * logging.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Support;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helcim API v2 client (static utility class).
 *
 * Integration contract (other lanes call this signature — do not change it):
 *   YSHelcimApiClient::request( $endpoint, $payload, $api_token, $idempotency_key = null, $method = 'POST' )
 *   → on success returns the decoded array; on failure returns WP_Error (code: ys_helcim_api_error).
 */
class YSHelcimApiClient {

	/**
	 * Base URL for the Helcim API v2.
	 *
	 * @var string
	 */
	private const API_BASE_URL = 'https://api.helcim.com/v2/';

	/**
	 * HTTP timeout in seconds.
	 *
	 * @var int
	 */
	private const TIMEOUT = 30;

	/**
	 * Send a request to the Helcim API.
	 *
	 * @param string      $endpoint        Endpoint path, e.g. 'helcim-pay/initialize' (appended to the API base).
	 * @param array       $payload         Request data (JSON body for POST; merged into the query string for GET).
	 * @param string      $api_token       Helcim API token.
	 * @param string|null $idempotency_key Idempotency key (required for purchase/refund; sent as the idempotency-key header when present).
	 * @param string      $method          HTTP method (defaults to POST).
	 * @return array|\WP_Error The decoded response array on success; a WP_Error on failure.
	 */
	public static function request( string $endpoint, array $payload, string $api_token, ?string $idempotency_key = null, string $method = 'POST' ) {
		$method = strtoupper( $method );
		$url    = self::API_BASE_URL . ltrim( $endpoint, '/' );

		if ( '' === trim( $api_token ) ) {
			YSHelcimLogger::error( 'API request rejected: empty api token', array( 'endpoint' => $endpoint ) );
			return new \WP_Error(
				'ys_helcim_api_error',
				__( 'The Helcim API token has not been configured. Please enter it in the payment settings first.', 'ys-helcim-via-fluentcart' )
			);
		}

		$headers = array(
			'api-token'    => $api_token,
			'content-type' => 'application/json',
			'accept'       => 'application/json',
		);

		if ( null !== $idempotency_key && '' !== $idempotency_key ) {
			$headers['idempotency-key'] = $idempotency_key;
		}

		$args = array(
			'method'  => $method,
			'headers' => $headers,
			'timeout' => self::TIMEOUT,
		);

		// GET requests: merge data into the query string; all other methods send it as a JSON body.
		if ( 'GET' === $method ) {
			if ( ! empty( $payload ) ) {
				$url = add_query_arg( $payload, $url );
			}
		} else {
			$args['body'] = wp_json_encode( $payload );
		}

		// Log the request (api-token and sensitive fields are masked by the Logger;
		// billingAddress contains PII — name/address/phone/email — so the whole block
		// is replaced with a placeholder and never written to the log).
		$log_payload = $payload;
		if ( isset( $log_payload['billingAddress'] ) ) {
			$log_payload['billingAddress'] = '[masked-pii]';
		}
		YSHelcimLogger::info(
			sprintf( 'API REQUEST: %s %s', $method, $url ),
			array(
				'payload'         => $log_payload,
				'api-token'       => $api_token,
				'idempotency-key' => $idempotency_key,
			)
		);

		$response = wp_remote_request( $url, $args );

		// Transport-layer error (DNS / timeout / TLS, etc.).
		if ( is_wp_error( $response ) ) {
			YSHelcimLogger::error(
				'API transport error',
				array(
					'endpoint' => $endpoint,
					'error'    => $response->get_error_message(),
				)
			);
			return new \WP_Error(
				'ys_helcim_api_error',
				__( 'Could not reach Helcim. Please try again shortly.', 'ys-helcim-via-fluentcart' ),
				array( 'transport_error' => $response->get_error_message() )
			);
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$body      = wp_remote_retrieve_body( $response );
		$decoded   = json_decode( $body, true );

		// Log the response (after masking).
		YSHelcimLogger::log(
			$http_code >= 400 ? 'error' : 'info',
			sprintf( 'API RESPONSE: HTTP %d from %s %s', $http_code, $method, $url ),
			array( 'response' => is_array( $decoded ) ? $decoded : $body )
		);

		// HTTP error: pull the Helcim errors payload into a readable message.
		if ( $http_code >= 400 ) {
			return new \WP_Error(
				'ys_helcim_api_error',
				self::extract_error_message( is_array( $decoded ) ? $decoded : array() ),
				array( 'http_code' => $http_code )
			);
		}

		// Success status code but the JSON did not parse → treat as failure (fail-closed).
		if ( ! is_array( $decoded ) ) {
			YSHelcimLogger::error(
				'API response is not valid JSON',
				array(
					'endpoint'  => $endpoint,
					'http_code' => $http_code,
				)
			);
			return new \WP_Error(
				'ys_helcim_api_error',
				__( 'Helcim returned an unexpected response. Please try again shortly.', 'ys-helcim-via-fluentcart' ),
				array( 'http_code' => $http_code )
			);
		}

		return $decoded;
	}

	/**
	 * Extract a readable message from a Helcim error response.
	 *
	 * The Helcim error format may be {"errors": "message"} or {"errors": {"field": "message", ...}}.
	 *
	 * @param array $decoded The decoded response.
	 * @return string The error message.
	 */
	private static function extract_error_message( array $decoded ): string {
		$errors = $decoded['errors'] ?? null;

		if ( is_string( $errors ) && '' !== $errors ) {
			return $errors;
		}

		if ( is_array( $errors ) && ! empty( $errors ) ) {
			$parts = array();
			array_walk_recursive(
				$errors,
				static function ( $value ) use ( &$parts ) {
					if ( is_scalar( $value ) ) {
						$parts[] = (string) $value;
					}
				}
			);
			if ( ! empty( $parts ) ) {
				return implode( ', ', $parts );
			}
		}

		return __( 'Helcim reported an unknown error.', 'ys-helcim-via-fluentcart' );
	}
}
