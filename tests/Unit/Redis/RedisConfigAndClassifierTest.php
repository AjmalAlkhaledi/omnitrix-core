<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Unit\Redis;

use Omnitrix\Core\Redis\ErrorClassifier;
use Omnitrix\Core\Redis\Exception\CommandException;
use Omnitrix\Core\Redis\Exception\ConnectionException;
use Omnitrix\Core\Redis\Exception\TimeoutException;
use Omnitrix\Core\Redis\RedisConfig;
use Omnitrix\Core\Runtime\Config\InvalidConfigurationException;
use Omnitrix\Core\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use RedisException;

#[CoversClass(RedisConfig::class)]
#[CoversClass(ErrorClassifier::class)]
final class RedisConfigAndClassifierTest extends TestCase
{
    public function testDefaultsAndValidation(): void
    {
        $c = RedisConfig::fromArray([]);
        self::assertSame(['127.0.0.1', 6379, 0, 2.0, 5.0, '', 16], [$c->host, $c->port, $c->database, $c->timeout, $c->readTimeout, $c->prefix, $c->poolMax]);
        $c = RedisConfig::fromArray(['host' => 'cache', 'port' => 63790, 'password' => 'p', 'database' => 2, 'read_timeout' => 1, 'prefix' => 'app:', 'pool' => ['max' => 4, 'acquire_timeout' => 0.5]]);
        self::assertSame(['cache', 63790, 'p', 2, 1.0, 'app:', 4, 0.5], [$c->host, $c->port, $c->password, $c->database, $c->readTimeout, $c->prefix, $c->poolMax, $c->poolAcquireTimeout]);
        foreach ([['hostname' => 'x'], ['port' => 0], ['database' => -1], ['pool' => ['min' => 1]], ['password' => 5], ['host' => '']] as $bad) {
            try {
                RedisConfig::fromArray($bad);
                self::fail('accepted ' . json_encode($bad));
            } catch (InvalidConfigurationException) {
            }
        }
    }

    public function testClassifiesPhpRedisMessages(): void
    {
        self::assertInstanceOf(TimeoutException::class, ErrorClassifier::classify(new RedisException('read error on connection to 127.0.0.1:6379')));
        self::assertInstanceOf(ConnectionException::class, ErrorClassifier::classify(new RedisException('Connection refused')));
        self::assertInstanceOf(ConnectionException::class, ErrorClassifier::classify(new RedisException('Redis server went away')));
        self::assertInstanceOf(ConnectionException::class, ErrorClassifier::classify(new RedisException('NOAUTH Authentication required.')));
        $command = ErrorClassifier::classify(new RedisException('WRONGTYPE Operation against a key holding the wrong kind of value'), 'LPUSH');
        self::assertInstanceOf(CommandException::class, $command);
        self::assertStringContainsString('(LPUSH)', $command->getMessage());
    }
}
