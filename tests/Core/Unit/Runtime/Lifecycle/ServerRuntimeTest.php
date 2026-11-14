<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Unit\Runtime\Lifecycle;

use LogicException;
use Omnitrix\Core\Runtime\Config\InvalidConfigurationException;
use Omnitrix\Core\Runtime\Lifecycle\LifecycleState;
use Omnitrix\Core\Runtime\Lifecycle\ServerRuntime;
use Omnitrix\Core\Tests\Support\RecordingExceptionHandler;
use Omnitrix\Core\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;

#[CoversClass(ServerRuntime::class)]
#[CoversClass(LifecycleState::class)]
final class ServerRuntimeTest extends TestCase
{
    public function testCreateValidatesServerSectionAndKeepsTheRestOfTheConfig(): void
    {
        $app = ServerRuntime::create(['server' => ['port' => 18555], 'database' => ['driver' => 'mysql']], new NullLogger(), new RecordingExceptionHandler());
        self::assertSame(LifecycleState::Created, $app->state());
        self::assertSame(18555, $app->runtimeConfig()->port);
        self::assertSame(['driver' => 'mysql'], $app->config()['database']);
        self::assertInstanceOf(NullLogger::class, $app->logger());
        self::assertInstanceOf(RecordingExceptionHandler::class, $app->exceptionHandler());
        self::assertNull($app->server());
    }

    public function testCreateRejectsInvalidServerSection(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        ServerRuntime::create(['server' => 'nope']);
    }

    public function testBuildRequiresAHandlerAndMovesToBuilt(): void
    {
        $app = ServerRuntime::create(['server' => ['port' => 18556, 'workers' => 1]], new NullLogger());
        try {
            $app->build();
            self::fail('build without handler must fail');
        } catch (LogicException $e) {
            self::assertStringContainsString('handle()', $e->getMessage());
        }

        $app->handle(static function (): void {});
        $server = $app->build();
        self::assertSame(LifecycleState::Built, $app->state());
        self::assertSame($server, $app->server());
        self::assertSame(18556, $server->port);

        $this->expectException(LogicException::class);
        $app->handle(static function (): void {}); // registration after build is a programming error
    }
}
