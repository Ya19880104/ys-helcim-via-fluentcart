<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\HelcimJs;

use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\HelcimJs\YSHelcimJsGateway;

final class GatewayAssetsTest extends TestCase
{
    public function testGatewayLoadsTheDocumentedHelcimJsSdk(): void
    {
        if (!defined('YS_HELCIM_FCT_URL')) {
            define('YS_HELCIM_FCT_URL', 'https://example.test/wp-content/plugins/ys-helcim/');
        }
        if (!defined('YS_HELCIM_FCT_VERSION')) {
            define('YS_HELCIM_FCT_VERSION', 'test');
        }

        $assets = (new YSHelcimJsGateway())->getEnqueueScriptSrc();

        self::assertSame('https://secure.myhelcim.com/js/version2.js', $assets[0]['src']);
        self::assertSame('ys-helcim-js-sdk', $assets[0]['handle']);
        self::assertSame(['ys-helcim-js-sdk'], $assets[1]['deps']);
    }
}
