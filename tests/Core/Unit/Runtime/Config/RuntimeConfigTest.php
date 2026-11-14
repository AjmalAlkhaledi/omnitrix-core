<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Unit\Runtime\Config;

use Omnitrix\Core\Runtime\Config\InvalidConfigurationException;
use Omnitrix\Core\Runtime\Config\RuntimeConfig;
use Omnitrix\Core\Runtime\Config\ServerMode;
use Omnitrix\Core\Support\Platform;
use Omnitrix\Core\Tests\Support\TestCase;
use OpenSwoole\Constant;
use OpenSwoole\Runtime;
use OpenSwoole\Server;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(RuntimeConfig::class)]
#[CoversClass(ServerMode::class)]
#[CoversClass(InvalidConfigurationException::class)]
final class RuntimeConfigTest extends TestCase
{
    public function testDefaultsAreSafeAndMapToOpenSwooleOptions(): void
    {
        $config = RuntimeConfig::fromArray([]);

        self::assertSame('127.0.0.1', $config->host);
        self::assertSame(8080, $config->port);
        self::assertSame(ServerMode::Process, $config->mode, 'process mode is the default because it is the only mode with graceful SIGTERM drain');
        self::assertSame(Runtime::HOOK_ALL, $config->hookFlags);
        self::assertSame('epoll', $config->reactor);
        self::assertFalse($config->daemonize);

        $settings = $config->toOpenSwooleSettings();
        self::assertSame($config->workers, $settings['worker_num']);
        self::assertTrue($settings['enable_coroutine']);
        self::assertFalse($settings['enable_server_token']);
        self::assertSame(Constant::LOG_WARNING, $settings['log_level']);
        self::assertSame(2 * 1024 * 1024, $settings['package_max_length']);
        self::assertArrayNotHasKey('log_file', $settings);
        self::assertArrayNotHasKey('pid_file', $settings);
    }

    public function testExplicitValuesAndRawOptionsPassThrough(): void
    {
        $config = RuntimeConfig::fromArray([
            'host' => '0.0.0.0', 'port' => 9090, 'mode' => 'process', 'workers' => 3, 'task_workers' => 1,
            'log_level' => 'error', 'log_file' => '/tmp/x.log', 'pid_file' => '/tmp/x.pid', 'http2' => true,
            'options' => ['heartbeat_idle_time' => 60, 'worker_num' => 99],
        ]);

        self::assertSame(Server::POOL_MODE, $config->mode->openSwooleMode());
        $settings = $config->toOpenSwooleSettings();
        self::assertSame(3, $settings['worker_num'], 'explicit option wins over raw options');
        self::assertSame(1, $settings['task_worker_num']);
        self::assertSame(60, $settings['heartbeat_idle_time']);
        self::assertSame('/tmp/x.log', $settings['log_file']);
        self::assertSame('/tmp/x.pid', $settings['pid_file']);
        self::assertTrue($settings['open_http2_protocol']);
        self::assertSame(Constant::LOG_ERROR, $settings['log_level']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidConfigs(): iterable
    {
        yield 'unknown key' => [['wrokers' => 2], "server.wrokers"];
        yield 'port out of range' => [['port' => 70000], 'server.port'];
        yield 'port wrong type' => [['port' => '8080'], 'server.port'];
        yield 'workers zero' => [['workers' => 0], 'server.workers'];
        yield 'bad mode' => [['mode' => 'threads'], 'server.mode'];
        yield 'bad log level' => [['log_level' => 'loud'], 'server.log_level'];
        yield 'bad reactor' => [['reactor' => 'kqueue'], 'server.reactor'];
        yield 'empty log file' => [['log_file' => ''], 'server.log_file'];
        yield 'bool wrong type' => [['daemonize' => 'yes'], 'server.daemonize'];
        yield 'options not array' => [['options' => 'x'], 'server.options'];
        yield 'options non-scalar' => [['options' => ['a' => []]], 'server.options'];
        yield 'package too small' => [['package_max_length' => 1024], 'server.package_max_length'];
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('invalidConfigs')]
    public function testRejectsInvalidValuesNamingThePath(array $input, string $path): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage("'{$path}'");
        RuntimeConfig::fromArray($input);
    }

    public function testIoUringIsRefusedWhenTheKernelCannotProvideIt(): void
    {
        if (Platform::supportsIoUring()) {
            self::assertSame('io_uring', RuntimeConfig::fromArray(['reactor' => 'io_uring'])->reactor);

            return;
        }
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('io_uring requested but unavailable');
        RuntimeConfig::fromArray(['reactor' => 'io_uring']);
    }
}
