<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database\Cache;

use Omnitrix\Core\Runtime\Config\InvalidConfigurationException;

use function array_key_exists;
use function array_keys;
use function array_values;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function preg_match;

/**
 * The `cache` option of a database connection: which tables have a primary-key row cache and how
 * long entries live.
 *
 * ```php
 * 'cache' => [
 *     'tables' => ['users', 'products', 'memberships' => ['user_id', 'team_id']],
 *     'ttl' => 300,
 * ],
 * ```
 *
 * Tables are listed by name (the primary key is read from the schema) or with their key columns.
 */
final readonly class CacheConfig
{
    private const array KNOWN = ['enabled', 'tables', 'ttl', 'negative', 'negative_ttl', 'lock_ttl', 'strict', 'prefix'];

    /**
     * @param array<string, list<string>|null> $tables table => key columns (null: read from the schema)
     */
    public function __construct(
        public bool $enabled,
        public array $tables,
        public int $ttl = 300,
        public bool $negative = true,
        public int $negativeTtl = 60,
        public int $lockTtl = 30,
        public bool $strict = false,
        public string $prefix = 'dbc',
    ) {}

    public static function disabled(): self
    {
        return new self(false, []);
    }

    /** @throws InvalidConfigurationException */
    public static function fromArray(mixed $section, string $connection): self
    {
        $path = "database.{$connection}.cache";
        if ($section === null) {
            return self::disabled();
        }
        if (!is_array($section)) {
            throw InvalidConfigurationException::at($path, 'expected an array');
        }
        foreach (array_keys($section) as $key) {
            if (!in_array($key, self::KNOWN, true)) {
                throw InvalidConfigurationException::at("{$path}.{$key}", 'unknown option; known options are ' . implode(', ', self::KNOWN));
            }
        }
        $tables = self::tables($section['tables'] ?? [], "{$path}.tables");
        $enabled = self::bool($section, 'enabled', $tables !== [], $path);

        return new self(
            enabled: $enabled && $tables !== [],
            tables: $tables,
            ttl: self::int($section, 'ttl', 300, 1, $path),
            negative: self::bool($section, 'negative', true, $path),
            negativeTtl: self::int($section, 'negative_ttl', 60, 1, $path),
            lockTtl: self::int($section, 'lock_ttl', 30, 1, $path),
            strict: self::bool($section, 'strict', false, $path),
            prefix: self::prefix($section['prefix'] ?? 'dbc', "{$path}.prefix"),
        );
    }

    public function caches(string $table): bool
    {
        return $this->enabled && array_key_exists($table, $this->tables);
    }

    /** @return array<string, list<string>|null> */
    private static function tables(mixed $tables, string $path): array
    {
        if (!is_array($tables)) {
            throw InvalidConfigurationException::at($path, 'expected a list of table names or table => key columns');
        }
        $result = [];
        foreach ($tables as $key => $value) {
            if (is_int($key)) {
                if (!is_string($value) || $value === '') {
                    throw InvalidConfigurationException::at($path, 'table names must be non-empty strings');
                }
                $result[$value] = null;

                continue;
            }
            if ($key === '') {
                throw InvalidConfigurationException::at($path, 'table names must be non-empty strings');
            }
            $columns = is_string($value) ? [$value] : $value;
            if (!is_array($columns) || $columns === []) {
                throw InvalidConfigurationException::at("{$path}.{$key}", 'expected a key column or a list of key columns');
            }
            $list = [];
            foreach (array_values($columns) as $column) {
                if (!is_string($column) || $column === '') {
                    throw InvalidConfigurationException::at("{$path}.{$key}", 'key columns must be non-empty strings');
                }
                $list[] = $column;
            }
            $result[$key] = $list;
        }

        return $result;
    }

    /** @param array<array-key, mixed> $section */
    private static function int(array $section, string $key, int $default, int $min, string $path): int
    {
        $value = $section[$key] ?? $default;
        if (!is_int($value) || $value < $min) {
            throw InvalidConfigurationException::at("{$path}.{$key}", "expected an integer >= {$min}");
        }

        return $value;
    }

    /** @param array<array-key, mixed> $section */
    private static function bool(array $section, string $key, bool $default, string $path): bool
    {
        $value = $section[$key] ?? $default;
        if (!is_bool($value)) {
            throw InvalidConfigurationException::at("{$path}.{$key}", 'expected a boolean');
        }

        return $value;
    }

    private static function prefix(mixed $value, string $path): string
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9_.-]+$/', $value) !== 1) {
            throw InvalidConfigurationException::at($path, 'expected letters, digits, ".", "_" or "-"');
        }

        return $value;
    }
}
