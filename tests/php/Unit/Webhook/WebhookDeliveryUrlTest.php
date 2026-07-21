<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Webhook;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Webhook\YSHelcimWebhookDeliveryUrl;

final class WebhookDeliveryUrlTest extends TestCase
{
    public function testAcceptsOnlyTheExactCleanHttpsRestRoute(): void
    {
        $url = 'https://shop.test/wp-json/ys-fc-pay/v1/events/card';
        self::assertSame($url, YSHelcimWebhookDeliveryUrl::validate($url));
    }

    #[DataProvider('invalidUrls')]
    public function testRejectsProviderForbiddenOrNonDeliverableUrls(string $url): void
    {
        $result = YSHelcimWebhookDeliveryUrl::validate($url);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_webhook_url_blocked', $result->get_error_code());
    }

    public static function invalidUrls(): iterable
    {
        yield 'plain http' => ['http://shop.test/wp-json/ys-fc-pay/v1/events/card'];
		yield 'forbidden host' => ['https://PAYMENTS-HELCIM.example/wp-json/ys-fc-pay/v1/events/card'];
        yield 'forbidden path' => ['https://shop.test/helcim/wp-json/ys-fc-pay/v1/events/card'];
        yield 'plain permalinks query' => ['https://shop.test/?rest_route=/ys-fc-pay/v1/events/card'];
        yield 'redirect-prone trailing slash' => ['https://shop.test/wp-json/ys-fc-pay/v1/events/card/'];
        yield 'wrong endpoint' => ['https://shop.test/wp-json/ys-fc-pay/v1/events/cards'];
        yield 'fragment' => ['https://shop.test/wp-json/ys-fc-pay/v1/events/card#fragment'];
        yield 'credentials' => ['https://user:pass@shop.test/wp-json/ys-fc-pay/v1/events/card'];
    }
}
