<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Webhook;

use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Webhook\YSHelcimWebhookHandler;
use YangSheep\Helcim\FluentCart\Webhook\YSHelcimWebhookRestController;

final class WebhookRestControllerTest extends TestCase
{
    public function testRegistersOnePublicPostRouteWhosePathDoesNotContainTheForbiddenProviderName(): void
    {
        $routes = [];
        $controller = new YSHelcimWebhookRestController(
            $this->handler(),
            static function (string $namespace, string $route, array $args) use (&$routes): bool {
                $routes[] = [$namespace, $route, $args];
                return true;
            },
            static fn (array $body, int $status): array => compact('body', 'status')
        );

        $controller->registerRoutes();

        self::assertCount(1, $routes);
        self::assertSame(['/events/card'], array_column($routes, 1));
        foreach ($routes as [$namespace, $route, $args]) {
            self::assertStringNotContainsString('helcim', strtolower($namespace . $route));
            self::assertSame('POST', $args['methods']);
            self::assertTrue(($args['permission_callback'])());
        }
        self::assertSame([$controller, 'card'], $routes[0][2]['callback']);
    }

    public function testPassesTheVerbatimBodyAndHeadersToTheCorrectChannel(): void
    {
        $calls = [];
        $handler = new YSHelcimWebhookHandler(
            static fn (): array => [['gateway' => 'ys_helcim_js', 'mode' => 'test', 'verifier_token' => 'v', 'api_token' => 'a']],
            static function (array $headers, string $raw) use (&$calls): bool {
                $calls[] = ['verify', $headers, $raw];
                return true;
            },
            static fn (): array => ['transactionId' => '51177061'],
            static function (array $proof, string $id, array $gateways) use (&$calls): array {
                unset($proof, $id);
                $calls[] = ['reconcile', $gateways];
                return ['code' => 200, 'message' => 'ok'];
            },
            static fn (): array => ['status' => 'matched', 'binding' => ['gateway' => 'ys_helcim_js', 'mode' => 'test']]
        );
        $controller = new YSHelcimWebhookRestController(
            $handler,
            static fn (): bool => true,
            static fn (array $body, int $status): array => compact('body', 'status')
        );
        $request = new class {
            public function get_body(): string { return '{"type":"cardTransaction","id":"51177061"}'; }
            public function get_headers(): array { return ['Webhook-Id' => ['event-1']]; }
        };

        $response = $controller->card($request);

        self::assertSame(200, $response['status']);
        self::assertSame('ok', $response['body']['message']);
        self::assertSame('verify', $calls[0][0]);
        self::assertSame(['Webhook-Id' => ['event-1']], $calls[0][1]);
        self::assertSame('reconcile', $calls[1][0]);
        self::assertSame([['gateway' => 'ys_helcim_js', 'mode' => 'test']], $calls[1][1]);
    }

    public function testInvalidRestRequestFailsClosed(): void
    {
        $controller = new YSHelcimWebhookRestController(
            $this->handler(),
            static fn (): bool => true,
            static fn (array $body, int $status): array => compact('body', 'status')
        );

        $response = $controller->card(new \stdClass());

        self::assertSame(400, $response['status']);
        self::assertSame('malformed request', $response['body']['message']);
    }

    private function handler(): YSHelcimWebhookHandler
    {
        return new YSHelcimWebhookHandler(
            static fn (): array => [['gateway' => 'ys_helcim', 'mode' => 'test', 'verifier_token' => 'v', 'api_token' => 'a']],
            static fn (): bool => true,
            static fn (): array => ['transactionId' => '51177061'],
            static fn (): array => ['code' => 200, 'message' => 'ok'],
            static fn (): array => ['status' => 'matched', 'binding' => ['gateway' => 'ys_helcim', 'mode' => 'test']]
        );
    }
}
