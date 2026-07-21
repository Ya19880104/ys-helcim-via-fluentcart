<?php
/**
 * Permission-protected REST boundary for positive refund resolution.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Refund;

use YangSheep\Helcim\FluentCart\Support\YSHelcimTransactionId;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class YSHelcimRefundResolutionRestController {

	public const REST_NAMESPACE = 'ys-fc-pay/v1';

	private const UUID_PATTERN = '[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';

	public const INSPECT_ROUTE = '/refund-resolutions/(?P<operation_uuid>' . self::UUID_PATTERN . ')/inspect';

	public const COMMIT_ROUTE = '/refund-resolutions/(?P<operation_uuid>' . self::UUID_PATTERN . ')/commit';

	/** @var callable */
	private $service_inspect;

	/** @var callable */
	private $service_commit;

	/** @var callable */
	private $is_logged_in;

	/** @var callable */
	private $nonce_verifier;

	/** @var callable */
	private $wordpress_capability_checker;

	/** @var callable */
	private $fluentcart_permission_checker;

	/** @var callable */
	private $current_user_id;

	/** @var callable */
	private $route_registrar;

	/** @var callable */
	private $response_factory;

	public function __construct(
		callable $service_inspect,
		callable $service_commit,
		callable $is_logged_in,
		callable $nonce_verifier,
		callable $wordpress_capability_checker,
		callable $fluentcart_permission_checker,
		callable $current_user_id,
		?callable $route_registrar = null,
		?callable $response_factory = null
	) {
		$this->service_inspect              = $service_inspect;
		$this->service_commit               = $service_commit;
		$this->is_logged_in                 = $is_logged_in;
		$this->nonce_verifier               = $nonce_verifier;
		$this->wordpress_capability_checker = $wordpress_capability_checker;
		$this->fluentcart_permission_checker = $fluentcart_permission_checker;
		$this->current_user_id              = $current_user_id;
		$this->route_registrar              = $route_registrar ?? static fn ( string $namespace, string $route, array $args ): bool => register_rest_route( $namespace, $route, $args );
		$this->response_factory             = $response_factory ?? static fn ( array $data, int $status ): \WP_REST_Response => new \WP_REST_Response( $data, $status );
	}

	/** Register only positive inspect and commit endpoints. */
	public function registerRoutes(): void {
		( $this->route_registrar )(
			self::REST_NAMESPACE,
			self::INSPECT_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'inspect' ),
				'permission_callback' => array( $this, 'permissionsCheck' ),
				'args'                => array(
					'operation_uuid'           => self::uuidSchema(),
					'candidate_transaction_id' => self::transactionIdSchema(),
				),
			)
		);

		( $this->route_registrar )(
			self::REST_NAMESPACE,
			self::COMMIT_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'commit' ),
				'permission_callback' => array( $this, 'permissionsCheck' ),
				'args'                => array(
					'operation_uuid'           => self::uuidSchema(),
					'candidate_transaction_id' => self::transactionIdSchema(),
					'challenge'                  => array(
						'required' => true,
						'type'     => 'string',
						'pattern'  => '^(?:[a-f0-9]{2}){32,64}$',
					),
					'confirmation_phrase'        => array(
						'required'  => true,
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 200,
					),
					'parent_attestation'         => array(
						'required' => true,
						'type'     => 'boolean',
					),
				),
			)
		);
	}

	/**
	 * Require login, REST nonce, WordPress administration, and FluentCart refund permission.
	 *
	 * @return true|\WP_Error
	 */
	public function permissionsCheck( mixed $request ) {
		try {
			if ( true !== ( $this->is_logged_in )() ) {
				return self::permissionError(
					'ys_helcim_resolution_authentication_required',
					__( 'Authentication is required.', 'ys-helcim-via-fluentcart' ),
					401
				);
			}

			$nonce = is_object( $request ) && method_exists( $request, 'get_header' )
				? $request->get_header( 'X-WP-Nonce' )
				: '';
			if (
				! is_string( $nonce ) ||
				'' === $nonce ||
				true !== ( $this->nonce_verifier )( $nonce, 'wp_rest' )
			) {
				return self::permissionError(
					'ys_helcim_resolution_invalid_rest_nonce',
					__( 'The REST nonce is invalid or expired.', 'ys-helcim-via-fluentcart' ),
					403
				);
			}

			if (
				true !== ( $this->wordpress_capability_checker )( 'manage_options' ) ||
				true !== ( $this->fluentcart_permission_checker )( 'orders/can_refund' )
			) {
				return self::permissionError(
					'ys_helcim_resolution_forbidden',
					__( 'You do not have permission to resolve this refund.', 'ys-helcim-via-fluentcart' ),
					403
				);
			}
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::permissionError(
				'ys_helcim_resolution_forbidden',
				__( 'Refund-resolution permission could not be verified.', 'ys-helcim-via-fluentcart' ),
				403
			);
		}

		return true;
	}

	/**
	 * Inspect exact positive provider evidence and return only confirmation data.
	 *
	 * @param mixed $request WP_REST_Request-compatible object.
	 * @return mixed WP_REST_Response-compatible object.
	 */
	public function inspect( mixed $request ): mixed {
		$parsed = self::parseRequest( $request, array( 'candidate_transaction_id' ) );
		if ( is_wp_error( $parsed ) ) {
			return $this->errorResponse( $parsed );
		}

		$candidate = $parsed['body']['candidate_transaction_id'] ?? null;
		$candidate = is_string( $candidate ) ? YSHelcimTransactionId::normalize( $candidate ) : null;
		if ( null === $candidate ) {
			return $this->errorResponse( self::invalidRequest() );
		}

		$actor = $this->actorUserId();
		if ( is_wp_error( $actor ) ) {
			return $this->errorResponse( $actor );
		}

		try {
			$result = ( $this->service_inspect )( $parsed['operation_uuid'], $candidate, $actor );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return $this->errorResponse( self::serviceUnavailable() );
		}
		if ( is_wp_error( $result ) ) {
			return $this->errorResponse( $result );
		}

		$data = self::inspectEnvelope( $result, $parsed['operation_uuid'], $candidate );
		if ( null === $data ) {
			return $this->errorResponse( self::serviceUnavailable() );
		}

		return $this->response( $data, 200 );
	}

	/**
	 * Commit a previously inspected positive resolution and expose safe local state.
	 *
	 * @param mixed $request WP_REST_Request-compatible object.
	 * @return mixed WP_REST_Response-compatible object.
	 */
	public function commit( mixed $request ): mixed {
		$body_keys = array(
			'candidate_transaction_id',
			'challenge',
			'confirmation_phrase',
			'parent_attestation',
		);
		$parsed    = self::parseRequest( $request, $body_keys );
		if ( is_wp_error( $parsed ) ) {
			return $this->errorResponse( $parsed );
		}

		$body      = $parsed['body'];
		$candidate = $body['candidate_transaction_id'] ?? null;
		$challenge = $body['challenge'] ?? null;
		$phrase    = $body['confirmation_phrase'] ?? null;
		$attested  = $body['parent_attestation'] ?? null;
		$candidate = is_string( $candidate ) ? YSHelcimTransactionId::normalize( $candidate ) : null;
		if (
			null === $candidate ||
			! is_string( $challenge ) ||
			1 !== preg_match( '/\A[a-f0-9]{64,128}\z/', $challenge ) ||
			0 !== strlen( $challenge ) % 2 ||
			! is_string( $phrase ) ||
			strlen( $phrase ) > 200 ||
			'' === trim( $phrase ) ||
			1 === preg_match( '/[\x00-\x1F\x7F]/', $phrase ) ||
			! is_bool( $attested ) ||
			self::confirmationPhrase( $parsed['operation_uuid'], $candidate, $attested ) !== $phrase
		) {
			return $this->errorResponse( self::invalidRequest() );
		}

		$actor = $this->actorUserId();
		if ( is_wp_error( $actor ) ) {
			return $this->errorResponse( $actor );
		}

		$service_request                   = $body;
		$service_request['operation_uuid'] = $parsed['operation_uuid'];
		try {
			$result = ( $this->service_commit )( $service_request, $actor );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return $this->errorResponse( self::serviceUnavailable() );
		}
		if ( is_wp_error( $result ) ) {
			return $this->errorResponse( $result );
		}

		$envelope = self::commitEnvelope( $result, $parsed['operation_uuid'] );
		if ( null === $envelope ) {
			return $this->errorResponse( self::serviceUnavailable() );
		}

		return $this->response( $envelope['data'], $envelope['status'] );
	}

	/** @param array<int,string> $body_keys @return array{operation_uuid:string,body:array<string,mixed>}|\WP_Error */
	private static function parseRequest( mixed $request, array $body_keys ) {
		if (
			! is_object( $request ) ||
			! method_exists( $request, 'get_url_params' ) ||
			! method_exists( $request, 'get_json_params' )
		) {
			return self::invalidRequest();
		}

		try {
			$route = $request->get_url_params();
			$body  = $request->get_json_params();
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::invalidRequest();
		}
		if (
			! is_array( $route ) ||
			! is_array( $body ) ||
			array( 'operation_uuid' ) !== array_values( array_keys( $route ) ) ||
			array_diff( $body_keys, array_keys( $body ) ) ||
			array_diff( array_keys( $body ), $body_keys )
		) {
			return self::invalidRequest();
		}

		$uuid = $route['operation_uuid'] ?? null;
		if ( ! self::isUuid( $uuid ) ) {
			return self::invalidRequest();
		}

		return array(
			'operation_uuid' => $uuid,
			'body'           => $body,
		);
	}

	/** @return int|\WP_Error */
	private function actorUserId() {
		try {
			$actor = ( $this->current_user_id )();
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::permissionError(
				'ys_helcim_resolution_forbidden',
				__( 'The refund-resolution actor could not be verified.', 'ys-helcim-via-fluentcart' ),
				403
			);
		}
		if ( ! is_int( $actor ) || $actor <= 0 ) {
			return self::permissionError(
				'ys_helcim_resolution_forbidden',
				__( 'The refund-resolution actor could not be verified.', 'ys-helcim-via-fluentcart' ),
				403
			);
		}

		return $actor;
	}

	/** @return array<string,mixed>|null */
	private static function inspectEnvelope( mixed $result, string $operation_uuid, string $candidate ): ?array {
		if ( ! is_array( $result ) ) {
			return null;
		}

		$source      = is_string( $result['source_transaction_id'] ?? null )
			? YSHelcimTransactionId::normalize( $result['source_transaction_id'] )
			: null;
		$challenge   = $result['challenge'] ?? null;
		$attestation = $result['parent_attestation_required'] ?? null;
		$phrase      = $result['confirmation_phrase'] ?? null;
		if (
			'confirmation_required' !== ( $result['status'] ?? null ) ||
			$operation_uuid !== ( $result['operation_uuid'] ?? null ) ||
			$candidate !== ( $result['candidate_transaction_id'] ?? null ) ||
			null === $source ||
			$source === $candidate ||
			'resolve_positive' !== ( $result['action'] ?? null ) ||
			! is_string( $result['proof_digest'] ?? null ) ||
			1 !== preg_match( '/\A[a-f0-9]{64}\z/', $result['proof_digest'] ) ||
			! is_bool( $attestation ) ||
			! is_string( $challenge ) ||
			1 !== preg_match( '/\A[a-f0-9]{64,128}\z/', $challenge ) ||
			0 !== strlen( $challenge ) % 2 ||
			null === self::sqlDate( $result['challenge_expires_at'] ?? null ) ||
			! is_string( $phrase ) ||
			self::confirmationPhrase( $operation_uuid, $candidate, $attestation ) !== $phrase
		) {
			return null;
		}

		return array(
			'status'                      => 'confirmation_required',
			'operation_uuid'              => $operation_uuid,
			'candidate_transaction_id'    => $candidate,
			'source_transaction_id'       => $source,
			'action'                      => 'resolve_positive',
			'parent_attestation_required' => $attestation,
			'challenge'                   => $challenge,
			'challenge_expires_at'        => $result['challenge_expires_at'],
			'confirmation_phrase'         => $phrase,
		);
	}

	/** @return array{data:array<string,mixed>,status:int}|null */
	private static function commitEnvelope( mixed $result, string $operation_uuid ): ?array {
		if (
			! is_array( $result ) ||
			'resolved' !== ( $result['status'] ?? null ) ||
			$operation_uuid !== ( $result['operation_uuid'] ?? null ) ||
			'succeeded' !== ( $result['remote_status'] ?? null ) ||
			! is_bool( $result['replayed'] ?? null ) ||
			! in_array( $result['local_recording_status'] ?? null, array( 'continued', 'attention_required' ), true )
		) {
			return null;
		}

		$data = array(
			'status'                 => 'resolved',
			'operation_uuid'         => $operation_uuid,
			'remote_status'          => 'succeeded',
			'replayed'               => $result['replayed'],
			'local_recording_status' => $result['local_recording_status'],
		);

		if ( 'attention_required' === $result['local_recording_status'] ) {
			$error_code = $result['local_error_code'] ?? null;
			if ( ! self::isSafeErrorCode( $error_code ) ) {
				return null;
			}
			$data['local_error_code'] = $error_code;
			return array(
				'data'   => $data,
				'status' => 202,
			);
		}

		$local = $result['local'] ?? null;
		if ( ! is_array( $local ) || ! in_array( $local['local_status'] ?? null, array( 'recorded', 'applied' ), true ) ) {
			return null;
		}
		if ( array_key_exists( 'operation_uuid', $local ) && $operation_uuid !== $local['operation_uuid'] ) {
			return null;
		}
		if (
			array_key_exists( 'local_transaction_id', $local ) &&
			( ! is_int( $local['local_transaction_id'] ) || $local['local_transaction_id'] <= 0 )
		) {
			return null;
		}
		if (
			array_key_exists( 'notification_status', $local ) &&
			! in_array( $local['notification_status'], array( 'pending', 'delivered', 'attention_required' ), true )
		) {
			return null;
		}
		if (
			array_key_exists( 'effect_status', $local ) &&
			! in_array(
				$local['effect_status'],
				array( 'waiting', 'ready_to_apply', 'applied', 'applied_with_warnings', 'stock_reconciliation_required' ),
				true
			)
		) {
			return null;
		}
		if ( array_key_exists( 'warnings', $local ) && ! self::validWarnings( $local['warnings'] ) ) {
			return null;
		}
		if (
			array_key_exists( 'manual_reconciliation_required', $local ) &&
			! is_bool( $local['manual_reconciliation_required'] )
		) {
			return null;
		}

		$data['local_status'] = $local['local_status'];
		foreach (
			array( 'local_transaction_id', 'notification_status', 'effect_status', 'warnings', 'manual_reconciliation_required' )
			as $field
		) {
			if ( array_key_exists( $field, $local ) ) {
				$data[ $field ] = $local[ $field ];
			}
		}

		return array(
			'data'   => $data,
			'status' => 'applied' === $local['local_status'] ? 200 : 202,
		);
	}

	private static function validWarnings( mixed $warnings ): bool {
		if ( ! is_array( $warnings ) || ! array_is_list( $warnings ) ) {
			return false;
		}
		foreach ( $warnings as $warning ) {
			if ( ! is_string( $warning ) || 1 !== preg_match( '/\A[a-z0-9_-]{1,100}\z/', $warning ) ) {
				return false;
			}
		}

		return true;
	}

	private static function confirmationPhrase( string $operation_uuid, string $candidate, bool $attestation ): string {
		return ( $attestation ? 'ATTEST AND RESOLVE ' : 'RESOLVE ' )
			. $operation_uuid
			. ' WITH HELCIM '
			. $candidate;
	}

	private static function isUuid( mixed $uuid ): bool {
		return is_string( $uuid ) && 1 === preg_match( '/\A' . self::UUID_PATTERN . '\z/', $uuid );
	}

	private static function sqlDate( mixed $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$date   = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		$errors = \DateTimeImmutable::getLastErrors();
		if ( false === $date || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ) {
			return null;
		}

		return $date->format( 'Y-m-d H:i:s' ) === $value ? $value : null;
	}

	private static function isSafeErrorCode( mixed $code ): bool {
		return is_string( $code ) && 1 === preg_match( '/\A[a-z0-9_-]{1,100}\z/', $code );
	}

	private function errorResponse( \WP_Error $error ): mixed {
		$status = self::errorStatus( $error );
		return $this->response(
			array(
				'error_code' => self::safeErrorCode( $error->get_error_code() ),
				'message'    => self::publicMessage( $status ),
			),
			$status
		);
	}

	private function response( array $data, int $status ): mixed {
		return ( $this->response_factory )( $data, $status );
	}

	private static function errorStatus( \WP_Error $error ): int {
		$data = $error->get_error_data();
		if (
			is_array( $data ) &&
			isset( $data['status'] ) &&
			in_array( $data['status'], array( 401, 403, 404, 409, 422, 503 ), true )
		) {
			return $data['status'];
		}

		$code = self::safeErrorCode( $error->get_error_code() );
		if ( str_contains( $code, 'not_found' ) ) {
			return 404;
		}
		if (
			str_contains( $code, 'conflict' ) ||
			str_contains( $code, 'candidate_used' ) ||
			str_contains( $code, 'scope_busy' )
		) {
			return 409;
		}
		if (
			str_contains( $code, 'invalid' ) ||
			str_contains( $code, 'proof_mismatch' ) ||
			str_contains( $code, 'confirmation' ) ||
			str_contains( $code, 'attestation' )
		) {
			return 422;
		}

		return 503;
	}

	private static function safeErrorCode( mixed $code ): string {
		$code = is_string( $code ) ? strtolower( $code ) : '';
		$code = preg_replace( '/[^a-z0-9_-]/', '', $code ) ?? '';
		return '' === $code ? 'ys_helcim_resolution_unavailable' : substr( $code, 0, 100 );
	}

	private static function publicMessage( int $status ): string {
		return match ( $status ) {
			401 => __( 'Authentication is required.', 'ys-helcim-via-fluentcart' ),
			403 => __( 'You do not have permission to resolve this refund.', 'ys-helcim-via-fluentcart' ),
			404 => __( 'The refund-resolution operation was not found.', 'ys-helcim-via-fluentcart' ),
			409 => __( 'This resolution conflicts with the current refund operation.', 'ys-helcim-via-fluentcart' ),
			422 => __( 'The refund-resolution request could not be accepted.', 'ys-helcim-via-fluentcart' ),
			default => __( 'The refund resolution could not be completed safely.', 'ys-helcim-via-fluentcart' ),
		};
	}

	private static function invalidRequest(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_invalid_resolution_request',
			__( 'The refund-resolution request is invalid.', 'ys-helcim-via-fluentcart' ),
			array( 'status' => 422 )
		);
	}

	private static function serviceUnavailable(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_resolution_service_unavailable',
			__( 'The refund-resolution service is unavailable.', 'ys-helcim-via-fluentcart' ),
			array( 'status' => 503 )
		);
	}

	/** @return array{required:bool,type:string,pattern:string} */
	private static function uuidSchema(): array {
		return array(
			'required' => true,
			'type'     => 'string',
			'pattern'  => '^' . self::UUID_PATTERN . '$',
		);
	}

	/** @return array{required:bool,type:string,pattern:string} */
	private static function transactionIdSchema(): array {
		return array(
			'required' => true,
			'type'     => 'string',
			'pattern'  => '^[1-9][0-9]*$',
		);
	}

	private static function permissionError( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
