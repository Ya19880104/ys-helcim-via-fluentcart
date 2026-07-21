<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Refund;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundPayload;

final class RefundPayloadTest extends TestCase
{
    public function testPayloadIsCanonicalAndHashStable(): void
    {
        $first = YSHelcimRefundPayload::normalize([
            'reason' => '<b>Customer request</b>',
            'item_ids' => [9, 3, 9],
            'manage_stock' => true,
            'refunded_items' => [
                ['id' => 9, 'restore_quantity' => 1],
                ['id' => 3, 'restore_quantity' => 2],
            ],
        ]);
        $second = YSHelcimRefundPayload::normalize([
            'refunded_items' => [
                ['restore_quantity' => 2, 'id' => 3],
                ['restore_quantity' => 1, 'id' => 9],
            ],
            'manage_stock' => true,
            'item_ids' => [3, 9],
            'reason' => 'Customer request',
        ]);

        self::assertSame($first, $second);
        self::assertSame([3, 9], $first['item_ids']);
        self::assertSame('Customer request', $first['reason']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', YSHelcimRefundPayload::hash($first));
        self::assertSame(YSHelcimRefundPayload::hash($first), YSHelcimRefundPayload::hash($second));
    }

    public function testCanonicalPayloadCanBeNormalizedAgainWithoutChangingItsIdentity(): void
    {
        $canonical = YSHelcimRefundPayload::normalize([
            'reason' => 'REST request builder',
            'item_ids' => [3],
            'actor_user_id' => 7,
        ]);

        self::assertSame($canonical, YSHelcimRefundPayload::normalize($canonical));
        self::assertSame(YSHelcimRefundPayload::hash($canonical), YSHelcimRefundPayload::hash(
            YSHelcimRefundPayload::normalize($canonical)
        ));
    }

    #[DataProvider('invalidPayloads')]
    public function testInvalidOrUnsafePayloadFailsClosed(array $payload): void
    {
        $this->expectException(\InvalidArgumentException::class);
        YSHelcimRefundPayload::normalize($payload);
    }

    /** @return array<string,array{array<string,mixed>}> */
    public static function invalidPayloads(): array
    {
        return [
            'non boolean stock flag' => [['manage_stock' => 'true']],
            'invalid item id' => [['item_ids' => ['3']]],
            'stock without items' => [['manage_stock' => true, 'item_ids' => [3], 'refunded_items' => []]],
            'stock item outside selection' => [[
                'manage_stock' => true,
                'item_ids' => [3],
                'refunded_items' => [['id' => 4, 'restore_quantity' => 1]],
            ]],
            'zero restore quantity' => [[
                'manage_stock' => true,
                'item_ids' => [3],
                'refunded_items' => [['id' => 3, 'restore_quantity' => 0]],
            ]],
            'subscription side effect' => [['cancel_subscription' => true]],
            'unsupported payload version' => [['version' => 2]],
            'string payload version' => [['version' => '1']],
        ];
    }
}
