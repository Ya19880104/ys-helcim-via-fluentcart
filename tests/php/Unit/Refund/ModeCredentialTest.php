<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Refund;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\HelcimJs\YSHelcimJsSettings;
use YangSheep\Helcim\FluentCart\HelcimPay\YSHelcimPaySettings;
use YangSheep\Helcim\FluentCart\YSHelcimFctBootstrap;

final class ModeCredentialTest extends TestCase
{
    #[DataProvider('settingsClasses')]
    public function testRefundCredentialIsSelectedByOriginalTransactionMode(string $settingsClass): void
    {
        $settings = new $settingsClass();
        $settings->settings['test_api_token'] = 'enc:test-credential';
        $settings->settings['live_api_token'] = 'enc:live-credential';

        self::assertSame('test-credential', $settings->getApiTokenForMode('test'));
        self::assertSame('live-credential', $settings->getApiTokenForMode('live'));
        self::assertSame('', $settings->getApiTokenForMode('sandbox'));
    }

    /** @return array<string,array{class-string}> */
    public static function settingsClasses(): array
    {
        return [
            'modal' => [YSHelcimPaySettings::class],
            'inline' => [YSHelcimJsSettings::class],
        ];
    }

    public function testBootstrapReportsTheActualStoreModeIndependentlyFromHistoricalCredentialSelection(): void
    {
        \FluentCart\Api\StoreSettings::$orderMode = 'test';
        \FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings::$settingsByClass[YSHelcimPaySettings::class] = [
            'live_api_token' => 'enc:historical-live-credential',
        ];

        try {
            $result = YSHelcimFctBootstrap::init()->resolveRefundCredential('ys_helcim', 'live');
        } finally {
            \FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings::$settingsByClass = [];
            \FluentCart\Api\StoreSettings::$orderMode = 'test';
        }

        self::assertIsArray($result);
        self::assertSame('test', $result['current_mode']);
        self::assertSame('historical-live-credential', $result['api_token']);
    }
}
