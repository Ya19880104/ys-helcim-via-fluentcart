<?php
/**
 * Clean WordPress REST routes for Helcim webhook delivery.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Webhook;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class YSHelcimWebhookRestController {

	public const REST_NAMESPACE = 'ys-fc-pay/v1';

	public const CARD_ROUTE = '/events/card';

	/** @var callable */
	private $route_registrar;

	/** @var callable */
	private $response_factory;

	public function __construct(
		private YSHelcimWebhookHandler $handler,
		?callable $route_registrar = null,
		?callable $response_factory = null
	) {
		$this->route_registrar = $route_registrar ?? static fn ( string $namespace, string $route, array $args ): bool =>
			register_rest_route( $namespace, $route, $args );
		$this->response_factory = $response_factory ?? static fn ( array $body, int $status ): \WP_REST_Response =>
			new \WP_REST_Response( $body, $status );
	}

	public function registerRoutes(): void {
		( $this->route_registrar )(
			self::REST_NAMESPACE,
			self::CARD_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'card' ),
				'permission_callback' => static fn (): bool => true,
			)
		);
	}

	public function card( mixed $request ): mixed {
		return $this->dispatch( $request );
	}

	private function dispatch( mixed $request ): mixed {
		if (
			! is_object( $request ) ||
			! method_exists( $request, 'get_body' ) ||
			! method_exists( $request, 'get_headers' )
		) {
			return ( $this->response_factory )( array( 'message' => 'malformed request' ), 400 );
		}

		try {
			$body    = $request->get_body();
			$headers = $request->get_headers();
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return ( $this->response_factory )( array( 'message' => 'malformed request' ), 400 );
		}
		if ( ! is_string( $body ) || ! is_array( $headers ) ) {
			return ( $this->response_factory )( array( 'message' => 'malformed request' ), 400 );
		}

		$result = $this->handler->handle( $body, $headers );
		return ( $this->response_factory )( $result['body'], $result['status'] );
	}
}
