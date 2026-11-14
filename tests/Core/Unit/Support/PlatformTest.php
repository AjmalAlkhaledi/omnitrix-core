<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Unit\Support;

use Omnitrix\Core\Support\Platform;
use Omnitrix\Core\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

use function defined;
use function version_compare;

use const PHP_VERSION;

#[CoversClass(Platform::class)]
final class PlatformTest extends TestCase
{
    public function testReportsLoadedOpenSwooleAtOrAboveMinimum(): void
    {
        self::assertTrue(Platform::hasOpenSwoole());
        $version = Platform::openSwooleVersion();
        self::assertNotNull($version);
        self::assertTrue(
            version_compare($version, Platform::MIN_OPENSWOOLE_VERSION, '>='),
            "Loaded openswoole {$version} is older than the verified minimum",
        );
    }

    public function testAssertSupportedPassesOnTheCurrentRuntime(): void
    {
        self::assertTrue(version_compare(PHP_VERSION, Platform::MIN_PHP_VERSION, '>='));
        Platform::assertSupported();
        self::assertSame(PHP_VERSION, Platform::phpVersion());
    }

    public function testKernelVersionIsParsedAsDottedNumbers(): void
    {
        $kernel = Platform::kernelVersion();
        self::assertNotNull($kernel);
        self::assertMatchesRegularExpression('/^\d+\.\d+(\.\d+)?$/', $kernel);
    }

    public function testIoUringSupportFollowsKernelVersionAndConstant(): void
    {
        $kernel = Platform::kernelVersion();
        self::assertNotNull($kernel);
        $expected = defined('OPENSWOOLE_IO_URING') && version_compare($kernel, Platform::MIN_IO_URING_KERNEL, '>=');
        self::assertSame($expected, Platform::supportsIoUring());
    }

    public function testDetectsExecutionInsideACoroutine(): void
    {
        self::assertTrue(Platform::inCoroutine(), 'co-phpunit must run tests inside a root coroutine');
    }
}
