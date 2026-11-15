<?php

declare(strict_types=1);

namespace Omnitrix\Facades;

use Omnitrix\Core\Database\Database;
use Omnitrix\Core\Database\DatabaseManager;
use Omnitrix\Core\Database\Pool\PooledConnection;
use Omnitrix\Core\Database\Query\Builder;
use Omnitrix\Core\Database\Query\Expression;

/**
 * Database access (documentation: Database).
 *
 * ```php
 * $user = DB::table('users')->where('id', 10)->first();
 * DB::table('users')->insert(['name' => 'John', 'email' => 'john@example.com']);
 * DB::transaction(fn () => DB::table('accounts')->where('id', 1)->decrement('balance', 50));
 * ```
 */
final class DB extends Facade
{
    public static function manager(): DatabaseManager
    {
        return self::resolve(DatabaseManager::class);
    }

    public static function connection(?string $name = null): Database
    {
        return self::manager()->connection($name);
    }

    public static function table(string $table): Builder
    {
        return self::connection()->table($table);
    }

    public static function query(): Builder
    {
        return self::connection()->query();
    }

    /**
     * @param list<mixed>|array<string, mixed> $bindings
     * @return list<array<string, mixed>>
     */
    public static function select(string $sql, array $bindings = []): array
    {
        return self::connection()->select($sql, $bindings);
    }

    /**
     * @param list<mixed>|array<string, mixed> $bindings
     * @return array<string, mixed>|null
     */
    public static function selectOne(string $sql, array $bindings = []): ?array
    {
        return self::connection()->selectOne($sql, $bindings);
    }

    /** @param list<mixed>|array<string, mixed> $bindings */
    public static function execute(string $sql, array $bindings = []): int
    {
        return self::connection()->execute($sql, $bindings);
    }

    /** @param list<mixed>|array<string, mixed> $bindings */
    public static function insert(string $sql, array $bindings = []): int
    {
        return self::connection()->insert($sql, $bindings);
    }

    /**
     * @template T
     * @param callable(PooledConnection): T $callback
     * @return T
     */
    public static function transaction(callable $callback, int $attempts = 1): mixed
    {
        return self::connection()->transaction($callback, $attempts);
    }

    /**
     * Runs `$callback` once the current transaction has committed (immediately outside a
     * transaction). Callbacks registered inside a nested transaction that rolls back are discarded.
     */
    public static function afterCommit(callable $callback): void
    {
        self::connection()->afterCommit($callback);
    }

    /**
     * Invalidates cached rows that were changed outside the query builder (another service, a
     * trigger, a manual fix). `$keys` are primary-key values, or tuples for composite keys.
     *
     * @param list<list<int|string>|int|string> $keys
     */
    public static function invalidate(string $table, array $keys, bool $absent = false): void
    {
        self::connection()->invalidate($table, $keys, $absent);
    }

    /**
     * Runs a raw SQL write that changes the given rows of a cached table, keeping the row cache
     * consistent.
     *
     * ```php
     * DB::writing('users', [10, 11], fn () => DB::execute('update users set score = score + 1 where id in (10, 11)'));
     * ```
     *
     * @template T
     *
     * @param list<list<int|string>|int|string> $keys
     * @param callable(): T                      $write
     *
     * @return T
     */
    public static function writing(string $table, array $keys, callable $write, bool $inserts = false): mixed
    {
        return self::connection()->writing($table, $keys, $write, $inserts);
    }

    /** @param list<mixed> $bindings */
    public static function raw(string $sql, array $bindings = []): Expression
    {
        return new Expression($sql, $bindings);
    }
}
