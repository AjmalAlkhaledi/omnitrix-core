<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Unit\Database;

use Omnitrix\Core\Database\Config\DatabaseConfig;
use Omnitrix\Core\Database\Config\PoolConfig;
use Omnitrix\Core\Database\Exception\ConnectionException;
use Omnitrix\Core\Database\Exception\ConstraintViolationException;
use Omnitrix\Core\Database\Exception\DeadlockException;
use Omnitrix\Core\Database\Exception\ErrorClassifier;
use Omnitrix\Core\Database\Exception\LockWaitTimeoutException;
use Omnitrix\Core\Database\Exception\QueryException;
use Omnitrix\Core\Database\Exception\QueryTimeoutException;
use Omnitrix\Core\Database\Exception\UniqueConstraintViolationException;
use Omnitrix\Core\Runtime\Config\InvalidConfigurationException;
use Omnitrix\Core\Tests\Support\TestCase;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(DatabaseConfig::class)]
#[CoversClass(PoolConfig::class)]
#[CoversClass(ErrorClassifier::class)]
#[CoversClass(QueryException::class)]
final class DatabaseConfigAndErrorsTest extends TestCase
{
    public function testDefaultsAndExplicitValues(): void
    {
        $c = DatabaseConfig::fromArray(['database' => 'app']);
        self::assertSame('mysql', $c->driver);
        self::assertSame('127.0.0.1', $c->host);
        self::assertSame(3306, $c->port);
        self::assertSame('utf8mb4', $c->charset);
        self::assertSame(1, $c->pool->min);
        self::assertSame(10, $c->pool->max);
        self::assertSame(5.0, $c->pool->acquireTimeout);
        self::assertSame(30.0, $c->statementTimeout);
        self::assertTrue($c->strict);

        $c = DatabaseConfig::fromArray(['driver' => 'mariadb', 'database' => 'x', 'port' => 33306, 'password' => 's', 'pool' => ['min' => 2, 'max' => 4, 'acquire_timeout' => 1, 'idle_timeout' => 0], 'connect_timeout' => 2, 'statement_timeout' => 0], 'analytics');
        self::assertSame('analytics', $c->name);
        self::assertSame(2, $c->pool->min);
        self::assertSame(1.0, $c->pool->acquireTimeout);
        self::assertSame(0.0, $c->pool->idleTimeout);
        self::assertSame(2.0, $c->connectTimeout);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalid(): iterable
    {
        yield 'missing database' => [[], 'database.default.database'];
        yield 'unknown key' => [['database' => 'x', 'hostname' => 'h'], 'database.default.hostname'];
        yield 'bad driver' => [['database' => 'x', 'driver' => 'pgsql'], 'database.default.driver'];
        yield 'min > max' => [['database' => 'x', 'pool' => ['min' => 5, 'max' => 2]], 'database.default.pool.min'];
        yield 'bad pool key' => [['database' => 'x', 'pool' => ['size' => 2]], 'database.default.pool.size'];
        yield 'port range' => [['database' => 'x', 'port' => 0], 'database.default.port'];
        yield 'options keys' => [['database' => 'x', 'options' => ['a' => 1]], 'database.default.options'];
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('invalid')]
    public function testRejectsInvalid(array $input, string $path): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage("'{$path}'");
        DatabaseConfig::fromArray($input);
    }

    /** @return iterable<string, array{string, int, string, class-string}> */
    public static function errors(): iterable
    {
        yield 'gone away' => ['HY000', 2006, 'MySQL server has gone away', ConnectionException::class];
        yield 'lost connection' => ['HY000', 2013, 'Lost connection to MySQL server during query', ConnectionException::class];
        yield 'refused' => ['HY000', 2002, 'Connection refused', ConnectionException::class];
        yield 'too many connections' => ['08004', 1040, 'Too many connections', ConnectionException::class];
        yield 'deadlock' => ['40001', 1213, 'Deadlock found when trying to get lock', DeadlockException::class];
        yield 'lock wait' => ['HY000', 1205, 'Lock wait timeout exceeded', LockWaitTimeoutException::class];
        yield 'mariadb statement time' => ['70100', 1969, 'Query execution was interrupted (max_statement_time exceeded)', QueryTimeoutException::class];
        yield 'mysql execution time' => ['HY000', 3024, 'Query execution was interrupted, maximum statement execution time exceeded', QueryTimeoutException::class];
        yield 'duplicate' => ['23000', 1062, "Duplicate entry 'a' for key 'users.email'", UniqueConstraintViolationException::class];
        yield 'fk' => ['23000', 1452, 'Cannot add or update a child row', ConstraintViolationException::class];
        yield 'not null' => ['23000', 1048, "Column 'name' cannot be null", ConstraintViolationException::class];
        yield 'syntax' => ['42000', 1064, 'You have an error in your SQL syntax', QueryException::class];
    }

    /** @param class-string $expected */
    #[DataProvider('errors')]
    public function testClassifiesPdoErrors(string $sqlState, int $code, string $message, string $expected): void
    {
        $pdo = new PDOException("SQLSTATE[{$sqlState}]: {$message}");
        $pdo->errorInfo = [$sqlState, $code, $message];
        $classified = ErrorClassifier::classify($pdo, 'select * from t where id = ?', [1]);

        self::assertInstanceOf($expected, $classified);
        self::assertSame($code, $classified->getCode());
        self::assertSame($pdo, $classified->getPrevious());
        if ($classified instanceof QueryException) {
            self::assertSame($sqlState, $classified->sqlState());
            self::assertSame(1, $classified->bindingCount());
            self::assertStringContainsString('select * from t', $classified->getMessage());
            self::assertStringNotContainsString('1]', $classified->sql(), 'bindings never appear in messages');
        }
    }
}
