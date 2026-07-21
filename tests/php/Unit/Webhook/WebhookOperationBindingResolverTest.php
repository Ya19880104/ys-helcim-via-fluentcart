<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Webhook;

use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimPurchaseOperation;
use YangSheep\Helcim\FluentCart\Webhook\YSHelcimWebhookOperationBindingResolver;

final class WebhookOperationBindingResolverTest extends TestCase
{
    private const UUID = '00000000-0000-4000-8000-000000000321';

    public function testReturnsOnlyTheExactDurablePurchaseGatewayAndMode(): void
    {
        $row = $this->row();
        $resolver = new YSHelcimWebhookOperationBindingResolver(
            static fn (string $uuid): ?array => $uuid === self::UUID ? $row : null
        );

        self::assertSame(
            [
                'status' => 'matched',
                'binding' => ['gateway' => 'ys_helcim_js', 'mode' => 'test'],
            ],
            $resolver->resolve(['invoiceNumber' => self::UUID])
        );
    }

    public function testAcceptsRealWpdbDecimalStringsAndAReplayedDecline(): void
    {
        $row = $this->row();
        $row['order_id'] = '10';
        $row['transaction_id'] = '20';
        $row['amount'] = '2100';
        $row['remote_status'] = 'declined';
        $resolver = new YSHelcimWebhookOperationBindingResolver(
            static fn (): array => $row
        );

        self::assertSame(
            [
                'status' => 'matched',
                'binding' => ['gateway' => 'ys_helcim_js', 'mode' => 'test'],
            ],
            $resolver->resolve(['invoiceNumber' => self::UUID])
        );
    }

    public function testRejectsUnknownMalformedOrUnsafeOperationState(): void
    {
        $row = $this->row();
        $row['remote_status'] = 'created';
        $reads = 0;
        $resolver = new YSHelcimWebhookOperationBindingResolver(
            static function () use (&$reads, $row): array {
                ++$reads;
                return $row;
            }
        );

        self::assertSame(['status' => 'unrelated'], $resolver->resolve(['invoiceNumber' => 'not-a-uuid']));
        self::assertSame(0, $reads);
        self::assertSame(['status' => 'conflict'], $resolver->resolve(['invoiceNumber' => self::UUID]));
        self::assertSame(1, $reads);
    }

    public function testDistinguishesUnknownOperationsFromUnavailableStorage(): void
    {
        $missing = new YSHelcimWebhookOperationBindingResolver(static fn (): null => null);
        $error = new YSHelcimWebhookOperationBindingResolver(
            static fn (): \WP_Error => new \WP_Error('db_unavailable', 'secret database detail')
        );
        $throwing = new YSHelcimWebhookOperationBindingResolver(
            static function (): void { throw new \RuntimeException('database offline'); }
        );

        self::assertSame(['status' => 'unrelated'], $missing->resolve(['invoiceNumber' => self::UUID]));
        self::assertSame(['status' => 'unavailable'], $error->resolve(['invoiceNumber' => self::UUID]));
        self::assertSame(['status' => 'unavailable'], $throwing->resolve(['invoiceNumber' => self::UUID]));
    }

    public function testRejectsOverflowAndNonCanonicalDecimalIdentityValues(): void
    {
        foreach (['00020', '+20', '20.0', '9223372036854775808'] as $unsafe) {
            $row = $this->row();
            $row['transaction_id'] = $unsafe;
            $resolver = new YSHelcimWebhookOperationBindingResolver(static fn (): array => $row);

            self::assertSame(['status' => 'conflict'], $resolver->resolve(['invoiceNumber' => self::UUID]));
        }
    }

    /** @return array<string,mixed> */
    private function row(): array
    {
        $purchase = YSHelcimPurchaseOperation::fromTransaction([
            'gateway' => 'ys_helcim_js',
            'order_id' => 10,
            'transaction_id' => 20,
            'transaction_uuid' => 'fc-transaction-123',
            'amount' => 2100,
            'currency' => 'USD',
            'payment_mode' => 'test',
        ]);
        self::assertInstanceOf(YSHelcimPurchaseOperation::class, $purchase);
        $row = $purchase->repositoryRecord(self::UUID, hash_hmac('sha256', 'token', wp_salt('auth')));
        self::assertIsArray($row);
        return $row + ['remote_status' => 'processing', 'local_status' => 'pending'];
    }
}
