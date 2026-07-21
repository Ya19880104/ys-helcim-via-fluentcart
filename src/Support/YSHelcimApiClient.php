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

use YangSheep\Helcim\FluentCart\Operations\YSHelcimIdempotency;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helcim API v2 client (static utility class).
 *
 * Integration contract (other lanes call this signature — do not change it):
 *   YSHelcimApiClient::request( $endpoint, $payload, $api_token, $idempotency_key = null, $method = 'POST' )
 *   → on success returns the decoded array; provider/transport failures return
 *   ys_helcim_api_error and local key-contract failures return
 *   ys_helcim_invalid_idempotency_key.
 */
class YSHelcimApiClient {

	public const MUTATION_NEVER_SENT             = 'never_sent';
	public const MUTATION_AUTHENTICATION_REJECTED = 'authentication_rejected';
	public const MUTATION_DEFINITIVE_DECLINE      = 'definitive_decline';
	public const MUTATION_OUTCOME_UNKNOWN         = 'outcome_unknown';

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
	 * @param string|null $idempotency_key Idempotency key (required for purchase/refund/reverse; sent as the idempotency-key header when present).
	 * @param string      $method          HTTP method (defaults to POST).
	 * @return array|\WP_Error The decoded response array on success; a WP_Error on failure.
	 */
	public static function request( string $endpoint, array $payload, string $api_token, ?string $idempotency_key = null, string $method = 'POST' ) {
		$method        = strtoupper( $method );
		$endpoint_path = trim( $endpoint, '/' );
		$url           = self::API_BASE_URL . $endpoint_path;

		if ( '' === trim( $api_token ) ) {
			YSHelcimLogger::error( 'API request rejected: empty api token', array( 'endpoint' => $endpoint ) );
			return new \WP_Error(
				'ys_helcim_api_error',
				__( 'The Helcim API token has not been configured. Please enter it in the payment settings first.', 'ys-helcim-via-fluentcart' ),
				array(
					'kind'                 => 'local',
					'indeterminate'        => false,
					'mutation_disposition' => self::MUTATION_NEVER_SENT,
				)
			);
		}

		$requires_idempotency = in_array(
			$endpoint_path,
			array( 'payment/purchase', 'payment/refund', 'payment/reverse' ),
			true
		);

		if ( ( $requires_idempotency && null === $idempotency_key ) || ( null !== $idempotency_key && ! YSHelcimIdempotency::isValid( $idempotency_key ) ) ) {
			YSHelcimLogger::error( 'API request rejected: invalid idempotency key', array( 'endpoint' => $endpoint ) );
			return new \WP_Error(
				'ys_helcim_invalid_idempotency_key',
				__( 'The payment operation could not be started safely. Please try again.', 'ys-helcim-via-fluentcart' ),
				array(
					'kind'                 => 'local',
					'indeterminate'        => false,
					'mutation_disposition' => self::MUTATION_NEVER_SENT,
				)
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
			$transport_error = YSHelcimSanitizer::errorText( $response->get_error_message(), 500 );
			YSHelcimLogger::error(
				'API transport error',
				array(
					'endpoint' => $endpoint,
					'error'    => $transport_error,
				)
			);
			return new \WP_Error(
				'ys_helcim_api_error',
				__( 'Could not reach Helcim. Please try again shortly.', 'ys-helcim-via-fluentcart' ),
				array(
					'kind'                 => 'transport',
					'indeterminate'        => true,
					'mutation_disposition' => self::MUTATION_OUTCOME_UNKNOWN,
					'transport_error'      => $transport_error,
				)
			);
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$body      = wp_remote_retrieve_body( $response );
		$decoded   = json_decode( $body, true );

		// Log the response (after masking).
		YSHelcimLogger::log(
			$http_code >= 400 ? 'error' : 'info',
			sprintf( 'API RESPONSE: HTTP %d from %s %s', $http_code, $method, $url ),
			array(
				'response' => is_array( $decoded )
					? YSHelcimSanitizer::logContext( $decoded )
					: '[unparseable body omitted]',
			)
		);

		// An unparseable body is ambiguous at every HTTP status. A proxy can
		// replace the provider response after the payment request has landed.
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
				array(
					'kind'                 => 'invalid_response',
					'http_code'            => $http_code,
					'indeterminate'        => true,
					'mutation_disposition' => self::MUTATION_OUTCOME_UNKNOWN,
				)
			);
		}

		// Only 2xx is success. Redirects, missing statuses, timeouts, throttling,
		// conflicts, and server failures cannot prove whether a mutation landed.
		if ( $http_code < 200 || $http_code >= 300 ) {
			$is_conflict        = 409 === $http_code;
			$is_server_error    = $http_code >= 500;
			$safe_errors        = array_key_exists( 'errors', $decoded )
				? YSHelcimSanitizer::providerErrors( $decoded['errors'] )
				: null;
			$is_definitive_decline = self::isDefinitivePurchaseDecline(
				$endpoint_path,
				$http_code,
				$decoded,
				$safe_errors
			);
			$is_abnormal_status = $http_code < 200 || ( $http_code >= 300 && $http_code < 400 );
			$is_authentication_rejection = 'POST' === $method
				&& 'payment/purchase' === $endpoint_path
				&& 401 === $http_code;
			$is_ambiguous_http  = ! $is_definitive_decline && ( $is_abnormal_status
				|| $is_server_error
				|| $is_conflict
				|| in_array( $http_code, array( 408, 425, 429 ), true ) );
			$mutation_disposition = self::MUTATION_OUTCOME_UNKNOWN;
			if ( $is_definitive_decline ) {
				$mutation_disposition = self::MUTATION_DEFINITIVE_DECLINE;
			} elseif ( $is_authentication_rejection ) {
				$mutation_disposition = self::MUTATION_AUTHENTICATION_REJECTED;
			}
			$error_data         = array(
				'kind'                 => $is_conflict ? 'conflict' : ( $is_ambiguous_http ? 'http' : 'provider' ),
				'http_code'            => $http_code,
				'indeterminate'        => $is_ambiguous_http,
				'mutation_disposition' => $mutation_disposition,
			);
			if ( null !== $safe_errors ) {
				$error_data['provider_errors'] = $safe_errors;
			}
			if ( $is_definitive_decline ) {
				$error_data['definitive_decline'] = true;
			}

			return new \WP_Error(
				'ys_helcim_api_error',
				self::extract_error_message( array( 'errors' => $safe_errors ) ),
				$error_data
			);
		}

		// Success status code but the JSON did not parse → treat as failure (fail-closed).
		return $decoded;
	}

	/**
	 * Helcim documents bank declines as response=0, HTTP 500, and a
	 * "Transaction Declined:" error. Every other 5xx remains indeterminate.
	 */
	private static function isDefinitivePurchaseDecline(
		string $endpoint_path,
		int $http_code,
		array $decoded,
		mixed $safe_errors
	): bool {
		if (
			'payment/purchase' !== $endpoint_path ||
			500 !== $http_code ||
			! array_key_exists( 'response', $decoded ) ||
			! in_array( $decoded['response'], array( 0, '0' ), true ) ||
			! is_string( $decoded['errors'] ?? null ) ||
			! is_string( $safe_errors ) ||
			strlen( $safe_errors ) > 500
		) {
			return false;
		}

		return 1 === preg_match( '/\ATransaction Declined:[^\r\n]{1,478}\z/', $safe_errors );
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
			return YSHelcimSanitizer::errorText( $errors, 1000 );
		}

		if ( is_array( $errors ) && ! empty( $errors ) ) {
			$parts = array();
			array_walk_recursive(
				$errors,
				static function ( $value ) use ( &$parts ) {
					if ( is_scalar( $value ) ) {
						$parts[] = YSHelcimSanitizer::errorText( (string) $value, 500 );
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
