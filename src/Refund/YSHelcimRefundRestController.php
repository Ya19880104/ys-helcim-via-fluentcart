<?php
/**
 * Permission-protected REST boundary for remote-first Helcim refunds.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Refund;

use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationState;
use YangSheep\Helcim\FluentCart\Support\YSHelcimSanitizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes only validated refund intent and journal-backed operation status.
 */
final class YSHelcimRefundRestController {

	public const REST_NAMESPACE = 'ys-fc-pay/v1';

	public const REFUND_ROUTE = '/orders/(?P<order_id>\d+)/refunds';

	public const OPTIONS_ROUTE = '/orders/(?P<order_id>\d+)/refund-options';

	public const OPERATION_ROUTE = '/refund-operations/(?P<operation_uuid>[0-9a-f-]{36})';

	/** @var callable */
	private $service_execute;

	/** @var callable */
	private $recorder;

	/** @var callable */
	private $operation_reader;

	/** @var callable */
	private $is_logged_in;

	/** @var callable */
	private $nonce_verifier;

	/** @var callable */
	private $permission_checker;

	/** @var callable */
	private $current_user_id;

	/** @var callable */
	private $route_registrar;

	/** @var callable */
	private $response_factory;

	/** @var callable|null */
	private $local_failure_recorder;

	/** @var callable|null */
	private $effect_state_reader;

	/** @var callable|null */
	private $options_loader;

	/** @var callable|null */
	private $stale_scope_expirer;

	public function __construct(
		private YSHelcimRefundRequest $request_builder,
		callable $service_execute,
		callable $recorder,
		callable $operation_reader,
		callable $is_logged_in,
		callable $nonce_verifier,
		callable $permission_checker,
		callable $current_user_id,
		?callable $route_registrar = null,
		?callable $response_factory = null,
		?callable $local_failure_recorder = null,
		?callable $effect_state_reader = null,
		?callable $options_loader = null,
		?callable $stale_scope_expirer = null
	) {
		$this->service_execute    = $service_execute;
		$this->recorder           = $recorder;
		$this->operation_reader   = $operation_reader;
		$this->is_logged_in       = $is_logged_in;
		$this->nonce_verifier     = $nonce_verifier;
		$this->permission_checker = $permission_checker;
		$this->current_user_id    = $current_user_id;
		$this->route_registrar    = $route_registrar ?? static function ( string $namespace, string $route, array $args ): bool {
			return register_rest_route( $namespace, $route, $args );
		};
		$this->response_factory   = $response_factory ?? static function ( array $data, int $status ): \WP_REST_Response {
			return new \WP_REST_Response( $data, $status );
		};
		$this->local_failure_recorder = $local_failure_recorder;
		$this->effect_state_reader    = $effect_state_reader;
		$this->options_loader         = $options_loader;
		$this->stale_scope_expirer    = $stale_scope_expirer;
	}

	/** Register the mutation and read-only reconciliation endpoints. */
	public function registerRoutes(): void {
		( $this->route_registrar )(
			self::REST_NAMESPACE,
			self::REFUND_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create' ),
				'permission_callback' => array( $this, 'permissionsCheck' ),
			)
		);

		( $this->route_registrar )(
			self::REST_NAMESPACE,
			self::OPTIONS_ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'options' ),
				'permission_callback' => array( $this, 'permissionsCheck' ),
			)
		);

		( $this->route_registrar )(
			self::REST_NAMESPACE,
			self::OPERATION_ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'show' ),
				'permission_callback' => array( $this, 'permissionsCheck' ),
			)
		);
	}

	/** Read the server-owned refundable Helcim transactions for one order. */
	public function options( mixed $request ): mixed {
		$route    = is_object( $request ) && method_exists( $request, 'get_url_params' )
			? $request->get_url_params()
			: null;
		$order_id = is_array( $route ) ? self::positiveInteger( $route['order_id'] ?? null ) : null;
		if ( null === $order_id ) {
			return $this->optionsErrorResponse(
				new \WP_Error(
					'ys_helcim_invalid_order',
					'Invalid order.',
					array( 'status' => 422 )
				)
			);
		}
		if ( null === $this->options_loader ) {
			return $this->optionsErrorResponse(
				new \WP_Error( 'ys_helcim_refund_options_unavailable', 'Refund options unavailable.' )
			);
		}
		if ( null !== $this->stale_scope_expirer ) {
			try {
				$expired = ( $this->stale_scope_expirer )( 'refund-order:' . $order_id );
			} catch ( \Throwable $exception ) {
				unset( $exception );
				$expired = new \WP_Error( 'ys_helcim_journal_unavailable', 'Refund recovery unavailable.' );
			}
			if ( is_wp_error( $expired ) ) {
				return $this->optionsErrorResponse( $expired );
			}
		}

		try {
			$options = ( $this->options_loader )( $order_id );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$options = new \WP_Error( 'ys_helcim_refund_options_unavailable', 'Refund options unavailable.' );
		}
		if ( is_wp_error( $options ) ) {
			return $this->optionsErrorResponse( $options );
		}
		if ( ! is_array( $options ) ) {
			return $this->optionsErrorResponse(
				new \WP_Error( 'ys_helcim_refund_options_unavailable', 'Refund options unavailable.' )
			);
		}

		return $this->response( $options, 200 );
	}

	/**
	 * Require an authenticated user, a REST nonce, and FluentCart refund permission.
	 *
	 * @return true|\WP_Error
	 */
	public function permissionsCheck( mixed $request ) {
		try {
			if ( true !== ( $this->is_logged_in )() ) {
				return self::permissionError(
					'ys_helcim_authentication_required',
					__( 'Authentication is required.', 'ys-helcim-via-fluentcart' ),
					401
				);
			}

			$nonce = is_object( $request ) && method_exists( $request, 'get_header' )
				? $request->get_header( 'X-WP-Nonce' )
				: '';
			if ( ! is_string( $nonce ) || '' === $nonce || ! ( $this->nonce_verifier )( $nonce, 'wp_rest' ) ) {
				return self::permissionError(
					'ys_helcim_invalid_rest_nonce',
					__( 'The REST nonce is invalid or expired.', 'ys-helcim-via-fluentcart' ),
					403
				);
			}

			if ( true !== ( $this->permission_checker )( 'orders/can_refund' ) ) {
				return self::permissionError(
					'ys_helcim_refund_forbidden',
					__( 'You do not have permission to refund this order.', 'ys-helcim-via-fluentcart' ),
					403
				);
			}
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::permissionError(
				'ys_helcim_refund_forbidden',
				__( 'Refund permission could not be verified.', 'ys-helcim-via-fluentcart' ),
				403
			);
		}

		return true;
	}

	/**
	 * Execute one remote-first refund and record locally only after proven success.
	 *
	 * @param mixed $request WP_REST_Request-compatible object.
	 * @return mixed WP_REST_Response-compatible object.
	 */
	public function create( mixed $request ): mixed {
		try {
			$actor_id = ( $this->current_user_id )();
			$context  = $this->request_builder->build( $request, is_int( $actor_id ) ? $actor_id : 0 );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return $this->errorResponse(
				new \WP_Error( 'ys_helcim_refund_context_unavailable', 'Refund context unavailable.' ),
				null,
				null,
				'not_started'
			);
		}

		if ( is_wp_error( $context ) ) {
			return $this->errorResponse( $context, self::requestOperationUuid( $request ), null, 'not_started' );
		}

		try {
			$result = ( $this->service_execute )( $context );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return $this->errorResponse(
				new \WP_Error( 'ys_helcim_refund_service_unavailable', 'Refund service unavailable.' ),
				(string) $context['operation_uuid'],
				null,
				'unknown'
			);
		}

		if ( is_wp_error( $result ) ) {
			return $this->errorResponse( $result, (string) $context['operation_uuid'], null, 'unknown' );
		}
		if ( ! $result instanceof YSHelcimRefundResult ) {
			return $this->errorResponse(
				new \WP_Error( 'ys_helcim_refund_service_unavailable', 'Refund service returned no durable result.' ),
				(string) $context['operation_uuid'],
				null,
				'unknown'
			);
		}

		$root_uuid      = $result->refundOperationUuid() ?? (string) $context['operation_uuid'];
		$effective_uuid = $result->effectiveOperationUuid() ?? $root_uuid;
		$provider_action = $result->providerAction() ?? 'refund';

		if ( YSHelcimRefundResult::SUCCEEDED === $result->status() ) {
			if ( ! self::isUuid( $root_uuid ) || ! self::isUuid( $effective_uuid ) ) {
				return $this->localUnknownResponse(
					$root_uuid,
					$effective_uuid,
					$provider_action,
					$result->vendorTransactionId(),
					'ys_helcim_operation_context_missing'
				);
			}

			try {
				$recorded = ( $this->recorder )( $effective_uuid );
			} catch ( \Throwable $exception ) {
				unset( $exception );
				$recorded = new \WP_Error( 'ys_helcim_local_recording_failed', 'Local recording failed.' );
			}

			if ( is_wp_error( $recorded ) ) {
				$recording_error = $recorded;
				$recorded        = $this->recoverCommittedRecording( $effective_uuid, $recorded );
			}

			if ( is_wp_error( $recorded ) ) {
				$local_status = $this->persistLocalFailure( $effective_uuid, $recording_error ?? $recorded )
					? YSHelcimOperationState::LOCAL_FAILED
					: 'unknown';
				return $this->localUnknownResponse(
					$root_uuid,
					$effective_uuid,
					$provider_action,
					$result->vendorTransactionId(),
					$recorded->get_error_code(),
					$local_status
				);
			}

			if ( ! self::validRecorderResult( $recorded, $effective_uuid ) ) {
				return $this->localUnknownResponse(
					$root_uuid,
					$effective_uuid,
					$provider_action,
					$result->vendorTransactionId(),
					'ys_helcim_local_recording_unverified'
				);
			}

			$local_status = (string) $recorded['local_status'];
			$notification_status = is_string( $recorded['notification_status'] ?? null ) && in_array(
				$recorded['notification_status'],
				array( 'pending', 'delivered', 'attention_required' ),
				true
			)
				? $recorded['notification_status']
				: ( YSHelcimOperationState::LOCAL_APPLIED === $local_status ? 'delivered' : 'pending' );
			$data = self::envelope(
					$root_uuid,
					$effective_uuid,
					$provider_action,
					$result->vendorTransactionId(),
					(int) $recorded['local_transaction_id'],
					YSHelcimRefundResult::SUCCEEDED,
					$local_status,
					$notification_status,
					false
				);
			foreach ( array( 'effect_status', 'warnings', 'manual_reconciliation_required' ) as $field ) {
				if ( array_key_exists( $field, $recorded ) ) {
					$data[ $field ] = $recorded[ $field ];
				}
			}
			return $this->response(
				$data,
				YSHelcimOperationState::LOCAL_APPLIED === $local_status ? 200 : 202
			);
		}

		$status = $result->status();
		$data   = self::envelope(
			$root_uuid,
			$effective_uuid,
			$provider_action,
			null,
			null,
			$status,
			YSHelcimOperationState::LOCAL_PENDING,
			'pending',
			in_array( $status, array( YSHelcimRefundResult::DECLINED, YSHelcimRefundResult::FAILED ), true )
		);
		if ( null !== $result->errorCode() && '' !== $result->errorCode() ) {
			$data['error_code'] = $result->errorCode();
		}

		if ( YSHelcimRefundResult::INDETERMINATE === $status ) {
			return $this->response( $data, 202 );
		}
		if ( in_array( $status, array( YSHelcimRefundResult::DECLINED, YSHelcimRefundResult::FAILED ), true ) ) {
			return $this->response( $data, 422 );
		}

		$data['retry_allowed'] = false;
		return $this->response( $data, 503 );
	}

	/**
	 * Read one operation's safe reconciliation state.
	 *
	 * @param mixed $request WP_REST_Request-compatible object.
	 * @return mixed WP_REST_Response-compatible object.
	 */
	public function show( mixed $request ): mixed {
		$route = is_object( $request ) && method_exists( $request, 'get_url_params' )
			? $request->get_url_params()
			: null;
		$uuid  = is_array( $route ) && is_string( $route['operation_uuid'] ?? null )
			? strtolower( $route['operation_uuid'] )
			: '';

		if ( ! self::isUuid( $uuid ) ) {
			return $this->errorResponse(
				new \WP_Error( 'ys_helcim_invalid_refund_request', 'Invalid operation UUID.', array( 'status' => 422 ) ),
				null,
				null,
				'not_started'
			);
		}

		try {
			$row = ( $this->operation_reader )( $uuid );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$row = new \WP_Error( 'ys_helcim_journal_unavailable', 'Operation journal unavailable.' );
		}

		if ( is_wp_error( $row ) ) {
			return $this->errorResponse( $row, $uuid, $uuid, 'unknown' );
		}
		if ( null === $row ) {
			return $this->errorResponse(
				new \WP_Error( 'ys_helcim_operation_not_found', 'Operation not found.', array( 'status' => 404 ) ),
				$uuid,
				$uuid,
				'unknown'
			);
		}
		if ( ! is_array( $row ) ) {
			return $this->errorResponse(
				new \WP_Error( 'ys_helcim_journal_unavailable', 'Operation journal returned invalid data.' ),
				$uuid,
				$uuid,
				'unknown'
			);
		}

		$data = self::operationEnvelope( $row );
		if ( null === $data ) {
			return $this->errorResponse(
				new \WP_Error( 'ys_helcim_journal_unavailable', 'Operation journal returned invalid data.' ),
				$uuid,
				$uuid,
				'unknown'
			);
		}

		if (
			null !== $this->effect_state_reader &&
			YSHelcimOperationState::REMOTE_SUCCEEDED === (string) ( $row['remote_status'] ?? '' ) &&
			in_array(
				(string) ( $row['local_status'] ?? '' ),
				array( YSHelcimOperationState::LOCAL_RECORDED, YSHelcimOperationState::LOCAL_APPLIED ),
				true
			)
		) {
			try {
				$effect_state = ( $this->effect_state_reader )( (string) $row['operation_uuid'] );
			} catch ( \Throwable $exception ) {
				unset( $exception );
				$effect_state = null;
			}

			$effect_state = self::validatedEffectState( $effect_state, (string) $row['local_status'] );
			if ( null === $effect_state ) {
				return $this->errorResponse(
					new \WP_Error( 'ys_helcim_effect_state_unavailable', 'Refund effect state unavailable.' ),
					$uuid,
					$uuid,
					'unknown'
				);
			}

			$data['notification_status']          = $effect_state['notification_status'];
			$data['effect_status']                = $effect_state['status'];
			$data['warnings']                     = $effect_state['warnings'];
			$data['manual_reconciliation_required'] = 'stock_reconciliation_required' === $effect_state['status'];
		}

		return $this->response( $data, 200 );
	}

	/** @return array{status:string,notification_status:string,warnings:array<int,string>}|null */
	private static function validatedEffectState( mixed $state, string $expected_local_status ): ?array {
		if ( ! is_array( $state ) ) {
			return null;
		}

		$status              = is_string( $state['status'] ?? null ) ? $state['status'] : '';
		$local_status        = is_string( $state['local_status'] ?? null ) ? $state['local_status'] : '';
		$notification_status = is_string( $state['notification_status'] ?? null ) ? $state['notification_status'] : '';
		$warnings            = $state['warnings'] ?? null;
		$effect_statuses     = $state['effect_statuses'] ?? null;

		if (
			$expected_local_status !== $local_status ||
			! in_array( $status, array( 'waiting', 'ready_to_apply', 'applied', 'applied_with_warnings', 'stock_reconciliation_required' ), true ) ||
			! in_array( $notification_status, array( 'pending', 'delivered', 'attention_required' ), true ) ||
			! is_array( $warnings ) ||
			! is_array( $effect_statuses ) ||
			array( 'stock_restore', 'customer_recount', 'refund_hooks' ) !== array_keys( $effect_statuses )
		) {
			return null;
		}

		$allowed_statuses = array( 'pending', 'processing', 'completed', 'failed', 'indeterminate' );
		foreach ( $effect_statuses as $effect => $effect_status ) {
			$allowed = $allowed_statuses;
			if ( 'stock_restore' === $effect ) {
				$allowed[] = 'skipped';
			}
			if ( ! is_string( $effect_status ) || ! in_array( $effect_status, $allowed, true ) ) {
				return null;
			}
		}

		$derived_warnings = array();
		foreach ( $effect_statuses as $effect => $effect_status ) {
			if ( in_array( $effect_status, array( 'failed', 'indeterminate' ), true ) ) {
				$derived_warnings[] = $effect;
			}
		}
		if ( array_values( $warnings ) !== $derived_warnings ) {
			return null;
		}

		$derived_notification = match ( $effect_statuses['refund_hooks'] ) {
			'completed' => 'delivered',
			'failed', 'indeterminate' => 'attention_required',
			default => 'pending',
		};
		if ( $notification_status !== $derived_notification ) {
			return null;
		}

		if ( YSHelcimOperationState::LOCAL_APPLIED === $local_status ) {
			$derived_status = $derived_warnings ? 'applied_with_warnings' : 'applied';
		} elseif ( in_array( $effect_statuses['stock_restore'], array( 'failed', 'indeterminate' ), true ) ) {
			$derived_status = 'stock_reconciliation_required';
		} elseif ( array_intersect( array_values( $effect_statuses ), array( 'pending', 'processing' ) ) ) {
			$derived_status = 'waiting';
		} else {
			$derived_status = 'ready_to_apply';
		}

		if ( $status !== $derived_status ) {
			return null;
		}

		return array(
			'status'              => $status,
			'notification_status' => $notification_status,
			'warnings'            => $derived_warnings,
		);
	}

	/** @param mixed $recorded */
	private static function validRecorderResult( mixed $recorded, string $effective_uuid ): bool {
		return is_array( $recorded )
			&& isset( $recorded['operation_uuid'], $recorded['local_transaction_id'], $recorded['local_status'] )
			&& $effective_uuid === $recorded['operation_uuid']
			&& is_int( $recorded['local_transaction_id'] )
			&& $recorded['local_transaction_id'] > 0
			&& in_array(
				$recorded['local_status'],
				array( YSHelcimOperationState::LOCAL_RECORDED, YSHelcimOperationState::LOCAL_APPLIED ),
				true
			);
	}

	/** @param array<string,mixed> $row @return array<string,mixed>|null */
	private static function operationEnvelope( array $row ): ?array {
		$effective_uuid = is_string( $row['operation_uuid'] ?? null )
			? strtolower( $row['operation_uuid'] )
			: '';
		$parent_uuid    = is_string( $row['parent_operation_uuid'] ?? null )
			? strtolower( trim( $row['parent_operation_uuid'] ) )
			: '';
		$root_uuid      = '' !== $parent_uuid ? $parent_uuid : $effective_uuid;
		$provider_action = is_string( $row['operation_type'] ?? null ) ? $row['operation_type'] : '';
		$remote_status   = is_string( $row['remote_status'] ?? null ) ? $row['remote_status'] : '';
		$local_status    = is_string( $row['local_status'] ?? null ) ? $row['local_status'] : '';
		$provider_id     = self::positiveIntegerString( $row['vendor_transaction_id'] ?? null );
		$local_id        = self::positiveInteger( $row['local_transaction_id'] ?? null );

		if (
			! self::isUuid( $root_uuid ) ||
			! self::isUuid( $effective_uuid ) ||
			! in_array( $provider_action, array( 'refund', 'reverse' ), true ) ||
			! in_array( $remote_status, YSHelcimOperationState::remoteStates(), true ) ||
			! in_array(
				$local_status,
				array(
					YSHelcimOperationState::LOCAL_PENDING,
					YSHelcimOperationState::LOCAL_APPLYING,
					YSHelcimOperationState::LOCAL_RECORDED,
					YSHelcimOperationState::LOCAL_APPLIED,
					YSHelcimOperationState::LOCAL_FAILED,
				),
				true
			) ||
			( YSHelcimOperationState::REMOTE_SUCCEEDED === $remote_status && null === $provider_id ) ||
			( in_array( $local_status, array( YSHelcimOperationState::LOCAL_RECORDED, YSHelcimOperationState::LOCAL_APPLIED ), true ) && null === $local_id )
		) {
			return null;
		}

		return self::envelope(
			$root_uuid,
			$effective_uuid,
			$provider_action,
			$provider_id,
			$local_id,
			$remote_status,
			$local_status,
			YSHelcimOperationState::LOCAL_APPLIED === $local_status ? 'delivered' : 'pending',
			in_array(
				$remote_status,
				array(
					YSHelcimOperationState::REMOTE_DECLINED,
					YSHelcimOperationState::REMOTE_FAILED,
					YSHelcimOperationState::REMOTE_CANCELED,
					YSHelcimOperationState::REMOTE_EXPIRED,
				),
				true
			)
		);
	}

	private function localUnknownResponse(
		string $root_uuid,
		string $effective_uuid,
		string $provider_action,
		?string $provider_transaction_id,
		string $error_code,
		string $local_status = 'unknown'
	): mixed {
		$safe_error_code     = self::safeErrorCode( $error_code );
		$status              = str_contains( $safe_error_code, 'accounting_drift' ) ||
			str_contains( $safe_error_code, 'operation_conflict' ) ||
			str_contains( $safe_error_code, 'scope_busy' )
			? 409
			: 503;
		$data               = self::envelope(
			$root_uuid,
			$effective_uuid,
			$provider_action,
			$provider_transaction_id,
			null,
			YSHelcimRefundResult::SUCCEEDED,
			$local_status,
			'pending',
			false
		);
		$data['error_code'] = $safe_error_code;
		$data['message']    = self::publicMessage( $status );

		return $this->response( $data, $status );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function recoverCommittedRecording( string $operation_uuid, \WP_Error $original_error ) {
		try {
			$operation = ( $this->operation_reader )( $operation_uuid );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return $original_error;
		}

		if (
			! is_array( $operation ) ||
			$operation_uuid !== (string) ( $operation['operation_uuid'] ?? '' ) ||
			YSHelcimOperationState::REMOTE_SUCCEEDED !== (string) ( $operation['remote_status'] ?? '' ) ||
			! in_array(
				(string) ( $operation['local_status'] ?? '' ),
				array( YSHelcimOperationState::LOCAL_RECORDED, YSHelcimOperationState::LOCAL_APPLIED ),
				true
			)
		) {
			return $original_error;
		}

		try {
			$replayed = ( $this->recorder )( $operation_uuid );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return $original_error;
		}

		return is_wp_error( $replayed ) ? $original_error : $replayed;
	}

	private function persistLocalFailure( string $operation_uuid, \WP_Error $error ): bool {
		if ( null === $this->local_failure_recorder ) {
			return false;
		}

		try {
			$recorded = ( $this->local_failure_recorder )(
				$operation_uuid,
				self::safeErrorCode( $error->get_error_code() ),
				YSHelcimSanitizer::errorText( $error->get_error_message() )
			);
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return false;
		}

		return true === $recorded;
	}

	private function errorResponse(
		\WP_Error $error,
		?string $root_uuid,
		?string $effective_uuid,
		string $remote_status
	): mixed {
		$status             = self::errorStatus( $error );
		$data               = self::envelope(
			$root_uuid,
			$effective_uuid,
			null,
			null,
			null,
			$remote_status,
			YSHelcimOperationState::LOCAL_PENDING,
			'pending',
			422 === $status
		);
		$data['error_code'] = self::safeErrorCode( $error->get_error_code() );
		$data['message']    = self::publicMessage( $status );

		return $this->response( $data, $status );
	}

	private function optionsErrorResponse( \WP_Error $error ): mixed {
		$status = self::errorStatus( $error );
		return $this->response(
			array(
				'error_code' => self::safeErrorCode( $error->get_error_code() ),
				'message'    => match ( $status ) {
					404 => __( 'The requested order was not found.', 'ys-helcim-via-fluentcart' ),
					422 => __( 'The requested order is invalid.', 'ys-helcim-via-fluentcart' ),
					default => __( 'Refund options could not be loaded safely.', 'ys-helcim-via-fluentcart' ),
				},
			),
			$status
		);
	}

	/** @return array<string,mixed> */
	private static function envelope(
		?string $root_uuid,
		?string $effective_uuid,
		?string $provider_action,
		?string $provider_transaction_id,
		?int $refund_transaction_id,
		string $remote_status,
		string $local_status,
		string $notification_status,
		bool $retry_allowed
	): array {
		return array(
			'operation_uuid'              => $root_uuid,
			'effective_operation_uuid'    => $effective_uuid,
			'provider_action'             => $provider_action,
			'provider_transaction_id'     => $provider_transaction_id,
			'refund_transaction_id'       => $refund_transaction_id,
			'remote_status'                => $remote_status,
			'local_status'                 => $local_status,
			'notification_status'          => $notification_status,
			'retry_allowed'                => $retry_allowed,
		);
	}

	/** @param array<string,mixed> $data */
	private function response( array $data, int $status ): mixed {
		return ( $this->response_factory )( $data, $status );
	}

	private static function errorStatus( \WP_Error $error ): int {
		$data = $error->get_error_data();
		if ( is_array( $data ) && isset( $data['status'] ) && in_array( $data['status'], array( 401, 403, 404, 409, 422, 503 ), true ) ) {
			return $data['status'];
		}

		$code = self::safeErrorCode( $error->get_error_code() );
		if ( str_contains( $code, 'not_found' ) ) {
			return 404;
		}
		if (
			str_contains( $code, 'conflict' ) ||
			str_contains( $code, 'scope_busy' ) ||
			str_contains( $code, 'credential_changed' ) ||
			str_contains( $code, 'account_drift' ) ||
			str_contains( $code, 'accounting_drift' ) ||
			str_contains( $code, 'mode_drift' )
		) {
			return 409;
		}
		if ( str_contains( $code, 'invalid' ) || str_contains( $code, 'declin' ) || str_contains( $code, 'validation' ) ) {
			return 422;
		}

		return 503;
	}

	private static function publicMessage( int $status ): string {
		return match ( $status ) {
			401 => __( 'Authentication is required.', 'ys-helcim-via-fluentcart' ),
			403 => __( 'You do not have permission to perform this refund action.', 'ys-helcim-via-fluentcart' ),
			404 => __( 'The refund operation was not found.', 'ys-helcim-via-fluentcart' ),
			409 => __( 'This refund conflicts with the current payment operation.', 'ys-helcim-via-fluentcart' ),
			422 => __( 'The refund request could not be accepted.', 'ys-helcim-via-fluentcart' ),
			default => __( 'The refund could not be completed safely. Review the operation before retrying.', 'ys-helcim-via-fluentcart' ),
		};
	}

	private static function requestOperationUuid( mixed $request ): ?string {
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_json_params' ) ) {
			return null;
		}
		try {
			$body = $request->get_json_params();
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return null;
		}

		$uuid = is_array( $body ) && is_string( $body['operation_uuid'] ?? null )
			? strtolower( $body['operation_uuid'] )
			: '';
		return self::isUuid( $uuid ) ? $uuid : null;
	}

	private static function isUuid( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match(
			'/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
			$value
		);
	}

	private static function positiveInteger( mixed $value ): ?int {
		$normalized = self::positiveIntegerString( $value );
		return null === $normalized ? null : (int) $normalized;
	}

	private static function positiveIntegerString( mixed $value ): ?string {
		if ( is_int( $value ) ) {
			$value = (string) $value;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A[1-9][0-9]*\z/', $value ) ) {
			return null;
		}
		$max = (string) PHP_INT_MAX;
		return strlen( $value ) < strlen( $max ) || ( strlen( $value ) === strlen( $max ) && strcmp( $value, $max ) <= 0 )
			? $value
			: null;
	}

	private static function safeErrorCode( mixed $code ): string {
		$code = is_string( $code ) ? strtolower( $code ) : '';
		$code = preg_replace( '/[^a-z0-9_\-]/', '', $code ) ?? '';
		return '' === $code ? 'ys_helcim_refund_unavailable' : substr( $code, 0, 100 );
	}

	private static function permissionError( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
