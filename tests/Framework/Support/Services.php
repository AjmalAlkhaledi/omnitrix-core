<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Support;

use PDO;
use PDOException;
use PHPUnit\Framework\Assert;

use function getenv;
use function is_string;
use function sprintf;

/**
 * Connection parameters for the local integration services started by `bin/services up`.
 * Integration tests fail (not skip) when a service is unreachable, so a missing service can
 * never make the suite pass silently.
 */
final class Services
{
    private function __construct() {}

    public static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    public static function dbHost(): string
    {
        return self::env('OMNITRIX_TEST_DB_HOST', '127.0.0.1');
    }

    public static function dbPort(): int
    {
        return (int) self::env('OMNITRIX_TEST_DB_PORT', '33306');
    }

    public static function dbName(): string
    {
        return self::env('OMNITRIX_TEST_DB_NAME', 'omnitrix_test');
    }

    public static function dbUser(): string
    {
        return self::env('OMNITRIX_TEST_DB_USER', 'root');
    }

    public static function dbPassword(): string
    {
        return self::env('OMNITRIX_TEST_DB_PASS', '');
    }

    public static function dbDsn(): string
    {
        return sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', self::dbHost(), self::dbPort(), self::dbName());
    }

    public static function redisHost(): string
    {
        return self::env('OMNITRIX_TEST_REDIS_HOST', '127.0.0.1');
    }

    public static function redisPort(): int
    {
        return (int) self::env('OMNITRIX_TEST_REDIS_PORT', '63790');
    }

    public static function upstreamPort(): int
    {
        return (int) self::env('OMNITRIX_TEST_UPSTREAM_PORT', '18080');
    }

    public static function pgPort(): int
    {
        return (int) self::env('OMNITRIX_TEST_PG_PORT', '55432');
    }

    public static function pgHost(): string
    {
        return self::env('OMNITRIX_TEST_PG_HOST', '127.0.0.1');
    }

    /**
     * A `database` connection section for the local PostgreSQL.
     *
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    public static function pgConfig(string $database, array $extra = []): array
    {
        return $extra + ['driver' => 'pgsql', 'host' => self::pgHost(), 'port' => self::pgPort(), 'database' => $database, 'username' => self::env('OMNITRIX_TEST_PG_USER', 'postgres'), 'password' => self::env('OMNITRIX_TEST_PG_PASS', ''), 'pool' => ['min' => 0, 'max' => 8]];
    }

    /**
     * A fresh PostgreSQL database for one test class; skips the test when PostgreSQL is not
     * running, unless OMNITRIX_TEST_PG=1 requires it (CI), where an unreachable server fails.
     */
    public static function pgDatabase(string $name): PDO
    {
        try {
            $root = new PDO(sprintf('pgsql:host=%s;port=%d;dbname=postgres;connect_timeout=3', self::pgHost(), self::pgPort()), self::env('OMNITRIX_TEST_PG_USER', 'postgres'), self::env('OMNITRIX_TEST_PG_PASS', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (PDOException $e) {
            if (self::env('OMNITRIX_TEST_PG', '0') === '1') {
                self::failUnreachable('PostgreSQL', $e->getMessage());
            }
            Assert::markTestSkipped('PostgreSQL is not running (start it with bin/services up): ' . $e->getMessage());
        }
        $root->exec('DROP DATABASE IF EXISTS "' . $name . '" WITH (FORCE)');
        $root->exec('CREATE DATABASE "' . $name . '"');

        return new PDO(sprintf('pgsql:host=%s;port=%d;dbname=%s', self::pgHost(), self::pgPort(), $name), self::env('OMNITRIX_TEST_PG_USER', 'postgres'), self::env('OMNITRIX_TEST_PG_PASS', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    public static function failUnreachable(string $service, string $detail): never
    {
        Assert::fail(sprintf('%s is not reachable (%s). Start local services with `bin/services up`.', $service, $detail));
    }
}
