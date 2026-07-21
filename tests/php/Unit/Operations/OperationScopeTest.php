<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Operations;

use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationScope;

final class OperationScopeTest extends TestCase
{
    public function testBusinessScopeBecomesStableFixedLengthAsciiKey(): void
    {
        $scope = YSHelcimOperationScope::fromBusinessKey('refund:parent-51177061');

        self::assertSame($scope, YSHelcimOperationScope::fromBusinessKey('refund:parent-51177061'));
        self::assertSame(69, strlen($scope));
        self::assertMatchesRegularExpression('/^yshs-[a-f0-9]{64}$/', $scope);
        self::assertSame($scope, YSHelcimOperationScope::fromBusinessKey($scope));
        self::assertNotSame($scope, YSHelcimOperationScope::fromBusinessKey('purchase:parent-51177061'));
    }

    public function testEmptyBusinessScopeFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        YSHelcimOperationScope::fromBusinessKey('   ');
    }
}
