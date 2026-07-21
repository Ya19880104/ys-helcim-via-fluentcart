<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

final class PurchaseProofIntegrationTest extends TestCase
{
    public function testEveryPaymentConfirmationPathUsesTheSharedStrictProof(): void
    {
        $root = dirname(__DIR__, 4);

        $modalProcessor = (string) file_get_contents($root . '/src/HelcimPay/YSHelcimPayProcessor.php');
        $modalConfirmation = (string) file_get_contents($root . '/src/HelcimPay/YSHelcimPayConfirmationService.php');
        $inlineProcessor = (string) file_get_contents($root . '/src/HelcimJs/YSHelcimJsProcessor.php');
        $webhookAdapter = (string) file_get_contents($root . '/src/HelcimJs/YSHelcimJsPurchaseResponseAdapter.php');

        self::assertStringContainsString('YSHelcimPayConfirmationService', $modalProcessor);
        self::assertStringContainsString('YSHelcimJsPurchaseResponseAdapter::toCoordinatorOutcome', $modalConfirmation);
        $inlineRuntime = (string) file_get_contents($root . '/src/HelcimJs/YSHelcimJsPurchaseRuntime.php');

        self::assertStringContainsString('YSHelcimJsPurchaseResponseAdapter::toCoordinatorOutcome', $inlineRuntime);
        self::assertStringContainsString('YSHelcimPurchaseProof::failureReason', $webhookAdapter);
        self::assertStringContainsString('YSHelcimTransactionId::normalize', $modalConfirmation);
        self::assertStringContainsString('YSHelcimTransactionId::normalize', $inlineProcessor);
        self::assertStringNotContainsString('function markPaid', $modalProcessor);
        self::assertStringNotContainsString('function markPaid', $inlineProcessor);
        self::assertStringNotContainsString('function reconcileCardTransaction', $inlineProcessor);
    }
}
