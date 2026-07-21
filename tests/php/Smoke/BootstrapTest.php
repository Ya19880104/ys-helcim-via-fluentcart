<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Smoke;

use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Support\YSHelcimApiClient;

final class BootstrapTest extends TestCase
{
    public function testProductionClassesAreAutoloadable(): void
    {
        self::assertTrue(class_exists(YSHelcimApiClient::class));
    }
}
