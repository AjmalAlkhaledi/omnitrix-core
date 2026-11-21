<?php

declare(strict_types=1);

namespace Omnitrix\Facades;

use Omnitrix\Core\Runtime\Context\SharedContext;

use function array_values;
use function is_array;

/**
 * Information about the current request or job, shared with everything it does (documentation:
 * The basics → Context).
 *
 * ```php
 * Context::add('tenant', $tenant->id);        // in every log line of this request: "context":{"tenant":7}
 * Context::addHidden('api_key_id', $key->id); // not logged
 * Queue::dispatch('reports.build', [...]);    // the job runs with the same context
 * ```
 *
 * Values are request-scoped (child coroutines see them) and must be JSON-compatible. Outside a
 * request or job there is no context: `add()` does nothing and `get()` returns the default.
 */
final class Context
{
    private function __construct() {}

    public static function add(string $key, mixed $value): void
    {
        SharedContext::set($key, $value);
    }

    /** @param array<string, mixed> $values */
    public static function addMany(array $values): void
    {
        foreach ($values as $key => $value) {
            SharedContext::set($key, $value);
        }
    }

    /** A value carried with jobs but never written to logs. */
    public static function addHidden(string $key, mixed $value): void
    {
        SharedContext::set($key, $value, hidden: true);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return SharedContext::get($key, $default);
    }

    public static function getHidden(string $key, mixed $default = null): mixed
    {
        return SharedContext::get($key, $default, hidden: true);
    }

    public static function has(string $key): bool
    {
        return SharedContext::has($key);
    }

    public static function hasHidden(string $key): bool
    {
        return SharedContext::has($key, hidden: true);
    }

    public static function forget(string $key): void
    {
        SharedContext::forget($key);
    }

    public static function forgetHidden(string $key): void
    {
        SharedContext::forget($key, hidden: true);
    }

    /**
     * Appends values to a list (`Context::push('breadcrumbs', 'checkout')`).
     */
    public static function push(string $key, mixed ...$values): void
    {
        $list = SharedContext::get($key, []);
        $list = is_array($list) ? array_values($list) : [$list];
        foreach ($values as $value) {
            $list[] = $value;
        }
        SharedContext::set($key, $list);
    }

    /** @return array<string, mixed> */
    public static function all(): array
    {
        return SharedContext::all();
    }

    /** @return array<string, mixed> */
    public static function allHidden(): array
    {
        return SharedContext::all(hidden: true);
    }
}
