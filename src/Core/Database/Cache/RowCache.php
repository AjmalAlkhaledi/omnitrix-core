<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database\Cache;

use JsonException;
use Omnitrix\Core\Redis\RedisClient;
use Redis;
use RedisCluster;
use WeakMap;

use function array_map;
use function array_merge;
use function bin2hex;
use function count;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;
use function json_decode;
use function json_encode;
use function random_bytes;
use function rawurlencode;
use function sha1;
use function str_contains;
use function strlen;
use function strpos;
use function substr;

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Redis side of the primary-key row cache.
 *
 * Every cached row has two keys, tagged with the table so that every key of a table is in one
 * slot of a Redis Cluster:
 *
 * - `{prefix}:{conn:table}:pk:v` — the row's version: a random token and the number of writers
 *   currently changing the row (`token:pending`);
 * - `{prefix}:{conn:table}:pk:r` — the cached row, stored with the tokens it was read under
 *   (`token\tepoch\tgeneration\tjson`).
 *
 * Each table also has an insert epoch (`{prefix}:{conn:table}:e`, same format) that validates
 * cached "row does not exist" entries, because inserts with generated keys cannot say in advance
 * which keys they create, and a generation (`{prefix}:{conn:table}:g`) that every entry of the
 * table depends on; it changes when the table itself changes (created, dropped, altered,
 * truncated) and is marked like a row version by writes whose triggers change the table.
 *
 * Protocol:
 *
 * - **read** (one MGET): an entry is served only when its token equals the current version token
 *   and no writer is pending (for absent rows also the epoch token, with no insert pending);
 * - **mark** (before a write statement): increments the pending count of each affected version;
 *   readers then bypass the cache and read the database;
 * - **publish** (after commit or rollback): installs a fresh random token and decrements the
 *   pending count, so every entry filled before or during the write becomes unreachable;
 * - **fill** (after a miss, Lua): stores the row only if the version is still the one observed
 *   before the database read and no writer is pending, so a slow reader can never store data
 *   that is older than a committed write.
 *
 * Versions expire (`lockTtl` while a writer is pending, a multiple of the entry TTL otherwise);
 * because tokens are random, an expired version can never revalidate an old entry.
 *
 * @internal used by the database layer
 */
final class RowCache
{
    private const string MARK = <<<'LUA'
        for i, key in ipairs(KEYS) do
          local cur = redis.call('GET', key)
          local token, pending = ARGV[i + 1], 0
          if cur then
            local sep = string.find(cur, ':', 1, true)
            token = string.sub(cur, 1, sep - 1)
            pending = tonumber(string.sub(cur, sep + 1)) or 0
          end
          redis.call('SET', key, token .. ':' .. (pending + 1), 'EX', ARGV[1])
        end
        return #KEYS
        LUA;

    /*
     * ARGV[1] mode: 'rotate' — a write committed: release one mark and install a fresh token;
     * 'keep' — a write rolled back: release one mark and keep the token (cached data is still
     * right); 'invalidate' — an outside change: fresh token, marks untouched.
     */
    private const string PUBLISH = <<<'LUA'
        for i, key in ipairs(KEYS) do
          local cur = redis.call('GET', key)
          local token, pending = ARGV[i + 3], 0
          if cur then
            local sep = string.find(cur, ':', 1, true)
            pending = tonumber(string.sub(cur, sep + 1)) or 0
            if ARGV[1] == 'keep' then token = string.sub(cur, 1, sep - 1) end
          end
          if ARGV[1] ~= 'invalidate' then pending = pending - 1 end
          if pending < 0 then pending = 0 end
          local ttl = ARGV[3]
          if pending > 0 then ttl = ARGV[2] end
          if cur or ARGV[1] ~= 'keep' then
            redis.call('SET', key, token .. ':' .. pending, 'EX', ttl)
          end
        end
        return #KEYS
        LUA;

    /* Extends the marks of a write that is still running (long transactions). */
    private const string EXTEND = <<<'LUA'
        for _, key in ipairs(KEYS) do
          local cur = redis.call('GET', key)
          if cur then
            local sep = string.find(cur, ':', 1, true)
            if (tonumber(string.sub(cur, sep + 1)) or 0) > 0 then
              redis.call('EXPIRE', key, ARGV[1])
            end
          end
        end
        return #KEYS
        LUA;

    private const string FILL = <<<'LUA'
        local function parse(v)
          if not v then return nil, 0 end
          local sep = string.find(v, ':', 1, true)
          return string.sub(v, 1, sep - 1), tonumber(string.sub(v, sep + 1)) or 0
        end
        local function current(key, expected, fresh, ttl)
          local token, pending = parse(redis.call('GET', key))
          if expected == '' then
            -- missing when looked up: create it (another fill of the same batch may have just done so)
            if token then
              if token == fresh and pending == 0 then return token end
              return nil
            end
            redis.call('SET', key, fresh .. ':0', 'EX', ttl, 'NX')
            token, pending = parse(redis.call('GET', key))
            if token ~= fresh then return nil end
            return token
          end
          if token ~= expected or pending ~= 0 then return nil end
          return token
        end
        local version = current(KEYS[1], ARGV[1], ARGV[2], ARGV[7])
        if not version then return 0 end
        local epoch = '-'
        if ARGV[3] ~= '-' then
          epoch = current(KEYS[3], ARGV[3], ARGV[4], ARGV[7])
          if not epoch then return 0 end
        end
        local generation = current(KEYS[4], ARGV[8], ARGV[9], ARGV[7])
        if not generation then return 0 end
        redis.call('SET', KEYS[2], version .. '\t' .. epoch .. '\t' .. generation .. '\t' .. ARGV[5], 'EX', ARGV[6])
        redis.call('EXPIRE', KEYS[4], ARGV[7])
        redis.call('EXPIRE', KEYS[1], ARGV[7])
        return 1
        LUA;

    private const string FILL_QUERY = <<<'LUA'
        local function parse(v)
          if not v then return nil, 0 end
          local sep = string.find(v, ':', 1, true)
          return string.sub(v, 1, sep - 1), tonumber(string.sub(v, sep + 1)) or 0
        end
        local function current(key, expected, fresh, ttl)
          local token, pending = parse(redis.call('GET', key))
          if expected == '' then
            if token then
              if token == fresh and pending == 0 then return token end
              return nil
            end
            redis.call('SET', key, fresh .. ':0', 'EX', ttl, 'NX')
            token, pending = parse(redis.call('GET', key))
            if token ~= fresh then return nil end
            return token
          end
          if token ~= expected or pending ~= 0 then return nil end
          return token
        end
        local tokens = {}
        for i = 2, #KEYS do
          local token = current(KEYS[i], ARGV[2 * i], ARGV[2 * i + 1], ARGV[2])
          if not token then return 0 end
          tokens[i - 1] = token
          redis.call('EXPIRE', KEYS[i], ARGV[2])
        end
        redis.call('SET', KEYS[1], table.concat(tokens, ',') .. '\t' .. ARGV[3], 'EX', ARGV[1])
        return 1
        LUA;

    /** @var array<string, string> script => sha1 */
    private array $shas = [];

    /** @var WeakMap<Redis|RedisCluster, true> connections whose server instance was checked */
    private WeakMap $verified;

    /** @var list<string> */
    private readonly array $tables;

    /**
     * @param list<string> $tables every table with a row or query cache (flushed when Redis restarts)
     */
    public function __construct(
        private readonly RedisClient $redis,
        public readonly CacheConfig $config,
        private readonly string $connection,
        array $tables = [],
    ) {
        $this->verified = new WeakMap();
        $this->tables = $tables;
    }

    /**
     * Runs `$fn` on a pooled connection. The first use of every connection checks the server's
     * `run_id` against the one recorded in Redis when the cache was last known to be complete. A
     * different id means the server restarted (or failed over) and may have loaded data from
     * before writes it never saw; every cached table is then retired before anything is served.
     *
     * @template T
     *
     * @param callable(Redis|RedisCluster): T $fn
     *
     * @return T
     */
    private function connection(callable $fn): mixed
    {
        return $this->redis->withConnection(function (Redis|RedisCluster $r) use ($fn): mixed {
            if (!isset($this->verified[$r])) {
                $this->verify($r);
                $this->verified[$r] = true;
            }

            return $fn($r);
        });
    }

    /**
     * Runs `$fn` for the keys on one connection: all at once on a single server; per hash tag on
     * a Redis Cluster, where a script may only touch keys of one slot (every key of a table shares
     * the table's tag, so this is one call per table).
     *
     * @param list<string>                                       $keys
     * @param callable(Redis|RedisCluster, list<string>): void   $fn
     */
    private function perSlot(array $keys, callable $fn): void
    {
        $this->connection(static function (Redis|RedisCluster $r) use ($keys, $fn): void {
            if (!$r instanceof RedisCluster) {
                $fn($r, $keys);

                return;
            }
            $groups = [];
            foreach ($keys as $key) {
                $open = strpos($key, '{');
                $close = $open === false ? false : strpos($key, '}', $open + 1);
                $groups[$open === false || $close === false ? $key : substr($key, $open, $close - $open + 1)][] = $key;
            }
            foreach ($groups as $group) {
                $fn($r, $group);
            }
        });
    }

    /**
     * Per table: compares the `run_id` of the server holding the table's keys with the one stored
     * next to them when the cache was last known to be complete. A different id (restart without
     * the latest data, failover to a replica) retires the table's entries.
     */
    private function verify(Redis|RedisCluster $redis): void
    {
        if ($this->tables === []) {
            return;
        }
        $fences = [];
        foreach ($this->tables as $table) {
            $fences[] = $this->tableTag($table) . ':fence';
        }
        $stored = $redis->mget($fences);
        $stored = is_array($stored) ? array_values($stored) : [];
        $single = null;
        foreach ($this->tables as $i => $table) {
            // On a cluster each table lives on the node owning its hash tag; ask that node.
            $runId = $redis instanceof RedisCluster
                ? self::runId($redis->info($fences[$i], 'server'))
                : ($single ??= self::runId($redis->info('server')));
            if ($runId !== '' && ($stored[$i] ?? false) === $runId) {
                continue;
            }
            $this->script($redis, self::PUBLISH, [$this->generationKey($table), $this->epochKey($table)], ['invalidate', (string) $this->config->lockTtl, (string) $this->versionTtl(), self::token(), self::token()]);
            if ($runId !== '') {
                $redis->set($fences[$i], $runId);
            }
        }
    }

    private static function runId(mixed $info): string
    {
        return is_array($info) && is_string($info['run_id'] ?? null) ? $info['run_id'] : '';
    }

    /**
     * Looks up rows by key. One round trip regardless of the number of keys.
     *
     * @param list<list<int|string>> $keys key tuples in the table's key column order
     *
     * @return list<LookupResult> one result per key, same order
     */
    public function lookup(string $table, array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $redisKeys = [];
        foreach ($keys as $key) {
            $redisKeys[] = $this->versionKey($table, $key);
            $redisKeys[] = $this->rowKey($table, $key);
        }
        $redisKeys[] = $this->epochKey($table);
        $redisKeys[] = $this->generationKey($table);
        $values = $this->connection(static fn(Redis|RedisCluster $r): mixed => $r->mget($redisKeys));
        $values = is_array($values) ? $values : [];
        $epoch = self::parseVersion($values[count($redisKeys) - 2] ?? false);
        $generation = self::parseVersion($values[count($redisKeys) - 1] ?? false);
        $results = [];
        foreach ($keys as $i => $key) {
            $results[] = $this->evaluate(self::parseVersion($values[2 * $i] ?? false), $values[2 * $i + 1] ?? false, $epoch, $generation);
        }

        return $results;
    }

    /**
     * Stores rows that were read from the database after a miss. Entries whose version changed
     * since the lookup, or that a writer is changing, are skipped. Absent rows are stored only when
     * negative caching is enabled.
     *
     * @param list<array{key: list<int|string>, row: array<string, mixed>|null, lookup: LookupResult}> $fills
     */
    public function fill(string $table, array $fills): void
    {
        $commands = [];
        // Table-wide keys (insert epoch, generation) get one fresh token per batch, so every fill of
        // the batch agrees on the value the first one creates.
        $epochToken = self::token();
        $generationToken = self::token();
        foreach ($fills as $fill) {
            $lookup = $fill['lookup'];
            if (!$lookup->fillable) {
                continue;
            }
            $negative = $fill['row'] === null;
            if ($negative && !$this->config->negative) {
                continue;
            }
            try {
                $json = json_encode($fill['row'], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } catch (JsonException) {
                continue; // binary column values that are not valid UTF-8 are not cached
            }
            $ttl = $negative ? $this->config->negativeTtl : $this->config->ttl;
            $commands[] = [
                [$this->versionKey($table, $fill['key']), $this->rowKey($table, $fill['key']), $this->epochKey($table), $this->generationKey($table)],
                [
                    $lookup->versionToken ?? '', self::token(),
                    $negative ? ($lookup->epochToken ?? '') : '-', $epochToken,
                    $json, (string) $ttl, (string) $this->versionTtl(),
                    $lookup->generationToken ?? '', $generationToken,
                ],
            ];
        }
        if ($commands === []) {
            return;
        }
        $this->connection(function (Redis|RedisCluster $r) use ($commands): void {
            foreach ($commands as [$keys, $args]) {
                $this->script($r, self::FILL, $keys, $args);
            }
        });
    }

    /**
     * Marks rows (and, for inserts, the table epoch) as being written: until {@see publish()}
     * readers bypass the cache for them.
     *
     * @param list<string> $versionKeys
     */
    public function mark(array $versionKeys): void
    {
        if ($versionKeys === []) {
            return;
        }
        $this->perSlot($versionKeys, function (Redis|RedisCluster $r, array $keys): void {
            $args = [(string) $this->config->lockTtl];
            foreach ($keys as $ignored) {
                $args[] = self::token();
            }
            $this->script($r, self::MARK, $keys, $args);
        });
    }

    /**
     * Ends a write started with {@see mark()}: installs fresh tokens and releases the marks.
     *
     * @param list<string> $versionKeys
     */
    public function publish(array $versionKeys): void
    {
        $this->rotate($versionKeys, 'rotate');
    }

    /**
     * Ends a write that was rolled back: releases the marks and keeps the tokens, because the
     * cached data still matches the database.
     *
     * @param list<string> $versionKeys
     */
    public function release(array $versionKeys): void
    {
        $this->rotate($versionKeys, 'keep');
    }

    /**
     * Keeps the marks of a running write alive for another `lock_ttl` seconds.
     *
     * @param list<string> $versionKeys
     */
    public function extend(array $versionKeys): void
    {
        if ($versionKeys === []) {
            return;
        }
        $this->perSlot($versionKeys, function (Redis|RedisCluster $r, array $keys): void {
            $this->script($r, self::EXTEND, $keys, [(string) $this->config->lockTtl]);
        });
    }

    /**
     * Invalidates rows changed outside the database layer (another application, a trigger, a
     * manual fix): installs fresh tokens without touching pending marks.
     *
     * @param list<string> $versionKeys
     */
    public function invalidate(array $versionKeys): void
    {
        $this->rotate($versionKeys, 'invalidate');
    }

    /**
     * Looks up a cached query result. `$dependencies` are the version keys the result depends on
     * (the table generation, the column key and one tag per filtered value), in a fixed order.
     *
     * @param list<string> $dependencies
     */
    public function lookupQuery(string $queryKey, array $dependencies): QueryLookup
    {
        $values = $this->connection(static fn(Redis|RedisCluster $r): mixed => $r->mget([$queryKey, ...$dependencies]));
        $values = is_array($values) ? $values : [];
        $tokens = [];
        foreach ($dependencies as $i => $ignored) {
            $version = self::parseVersion($values[$i + 1] ?? false);
            if ($version !== null && $version[1] > 0) {
                return QueryLookup::bypass(); // a writer is changing rows the result may contain
            }
            $tokens[] = $version[0] ?? null;
        }
        $entry = $values[0] ?? false;
        if (is_string($entry) && !in_array(null, $tokens, true)) {
            $parts = explode("\t", $entry, 2);
            if (count($parts) === 2 && $parts[0] === implode(',', $tokens)) {
                $rows = self::decodeRows($parts[1]);
                if ($rows !== null) {
                    return QueryLookup::hit($rows);
                }
            }
        }

        return QueryLookup::miss($tokens);
    }

    /**
     * Stores a query result read after a miss, unless one of its dependencies changed since the
     * lookup or is being written.
     *
     * @param list<string>               $dependencies
     * @param list<array<string, mixed>> $rows
     */
    public function fillQuery(string $queryKey, array $dependencies, QueryLookup $lookup, array $rows, int $ttl): void
    {
        if (!$lookup->fillable) {
            return;
        }
        try {
            $json = json_encode($rows, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            return;
        }
        $args = [(string) $ttl, (string) $this->versionTtl(), $json];
        foreach ($dependencies as $i => $ignored) {
            $args[] = $lookup->tokens[$i] ?? '';
            $args[] = self::token();
        }
        $this->connection(fn(Redis|RedisCluster $r): mixed => $this->script($r, self::FILL_QUERY, [$queryKey, ...$dependencies], $args));
    }

    public function queryKey(string $table, string $hash): string
    {
        return $this->tableTag($table) . ':q:' . $hash;
    }

    /** Version key of "rows whose `$column` compares equal to the value with this tag". */
    public function tagKey(string $table, string $column, string $tag): string
    {
        return $this->tableTag($table) . ':t:' . rawurlencode($column) . '=' . (strlen($tag) > 64 ? sha1($tag) : rawurlencode($tag));
    }

    /** Version key that every cached query filtering on `$column` depends on. */
    public function columnKey(string $table, string $column): string
    {
        return $this->tableTag($table) . ':c:' . rawurlencode($column);
    }

    /** @param list<int|string> $key */
    public function versionKey(string $table, array $key): string
    {
        return $this->tag($table, $key) . ':v';
    }

    /** @param list<int|string> $key */
    public function rowKey(string $table, array $key): string
    {
        return $this->tag($table, $key) . ':r';
    }

    public function epochKey(string $table): string
    {
        return $this->tableTag($table) . ':e';
    }

    public function generationKey(string $table): string
    {
        return $this->tableTag($table) . ':g';
    }

    private function tableTag(string $table): string
    {
        return $this->config->prefix . ':{' . rawurlencode($this->connection) . ':' . rawurlencode($table) . '}';
    }

    /**
     * Retires every cached entry of the table at once (after the table was created, dropped,
     * altered or truncated). Row writes never need this.
     */
    public function flush(string $table): void
    {
        $this->rotate([$this->generationKey($table), $this->epochKey($table)], 'invalidate');
    }

    /**
     * @param list<string>                  $versionKeys
     * @param 'rotate'|'keep'|'invalidate'  $mode
     */
    private function rotate(array $versionKeys, string $mode): void
    {
        if ($versionKeys === []) {
            return;
        }
        $this->perSlot($versionKeys, function (Redis|RedisCluster $r, array $keys) use ($mode): void {
            $args = [$mode, (string) $this->config->lockTtl, (string) $this->versionTtl()];
            foreach ($keys as $ignored) {
                $args[] = self::token();
            }
            $this->script($r, self::PUBLISH, $keys, $args);
        });
    }

    /**
     * @param array{string, int}|null $version
     * @param array{string, int}|null $epoch
     * @param array{string, int}|null $generation
     */
    private function evaluate(?array $version, mixed $entry, ?array $epoch, ?array $generation): LookupResult
    {
        if (($version !== null && $version[1] > 0) || ($generation !== null && $generation[1] > 0)) {
            return LookupResult::bypass(); // a writer is changing the row (or, through a trigger, the table)
        }
        $versionToken = $version[0] ?? null;
        $epochToken = $epoch !== null && $epoch[1] === 0 ? $epoch[0] : null;
        $epochFillable = $epoch === null || $epoch[1] === 0;
        $generationToken = $generation[0] ?? null;
        if ($versionToken !== null && $generationToken !== null && is_string($entry)) {
            $parts = explode("\t", $entry, 4);
            if (count($parts) === 4 && $parts[0] === $versionToken && $parts[2] === $generationToken) {
                if ($parts[3] !== 'null') {
                    $row = self::decodeRow($parts[3]);
                    if ($row !== null) {
                        return LookupResult::hit($row);
                    }
                } elseif ($epochToken !== null && $parts[1] === $epochToken) {
                    return LookupResult::absent();
                }
            }
        }

        return LookupResult::miss($versionToken, $epochToken, $epochFillable, $generationToken);
    }

    /** @return list<array<string, mixed>>|null */
    private static function decodeRows(string $json): ?array
    {
        try {
            $rows = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($rows)) {
            return null;
        }
        $list = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                return null;
            }
            $typed = [];
            foreach ($row as $column => $value) {
                $typed[(string) $column] = $value;
            }
            $list[] = $typed;
        }

        return $list;
    }

    /** @return array<string, mixed>|null */
    private static function decodeRow(string $json): ?array
    {
        try {
            $row = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($row)) {
            return null;
        }
        $typed = [];
        foreach ($row as $column => $value) {
            $typed[(string) $column] = $value;
        }

        return $typed;
    }

    /** @return array{string, int}|null */
    private static function parseVersion(mixed $value): ?array
    {
        if (!is_string($value) || !str_contains($value, ':')) {
            return null;
        }
        $sep = (int) strpos($value, ':');
        $pending = substr($value, $sep + 1);

        return [substr($value, 0, $sep), is_numeric($pending) ? (int) $pending : 0];
    }

    /**
     * @param list<string> $keys
     * @param list<string> $args
     */
    private function script(Redis|RedisCluster $redis, string $script, array $keys, array $args): mixed
    {
        $sha = $this->shas[$script] ??= sha1($script);
        $result = $redis->evalsha($sha, array_merge($keys, $args), count($keys));
        $error = $redis->getLastError();
        if ($result === false && is_string($error) && str_contains($error, 'NOSCRIPT')) {
            // First use on this server (or after SCRIPT FLUSH): send the script itself.
            $redis->clearLastError();
            $result = $redis->eval($script, array_merge($keys, $args), count($keys));
        }

        return $result;
    }

    /** @param list<int|string> $key */
    private function tag(string $table, array $key): string
    {
        // The row key carries the table's hash tag: a lookup (row + table keys in one MGET) and
        // every script stay within one slot on a Redis Cluster.
        return $this->tableTag($table) . ':'
            . implode(',', array_map(static fn(int|string $v): string => rawurlencode(is_int($v) ? (string) $v : $v), $key));
    }

    private function versionTtl(): int
    {
        return 2 * $this->config->ttl;
    }

    private static function token(): string
    {
        return bin2hex(random_bytes(8));
    }
}
