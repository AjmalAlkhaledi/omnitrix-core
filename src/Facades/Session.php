<?php

declare(strict_types=1);

namespace Omnitrix\Facades;

use LogicException;
use Omnitrix\Core\Runtime\Context\ContextStorage;
use Omnitrix\Session\StartSession;
use Omnitrix\Session\Store;

/**
 * The current request's session (documentation: The basics → Sessions); needs the StartSession
 * middleware on the route.
 *
 * ```php
 * Session::put('cart', $items);
 * Session::flash('status', 'Saved');
 * Session::regenerate(); // after login
 * ```
 */
final class Session
{
    private function __construct() {}

    public static function store(): Store
    {
        $store = ContextStorage::current()?->get(StartSession::ATTRIBUTE);
        if (!$store instanceof Store) {
            throw new LogicException('No session: add the StartSession middleware to the route');
        }

        return $store;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::store()->get($key, $default);
    }

    public static function has(string $key): bool
    {
        return self::store()->has($key);
    }

    public static function put(string $key, mixed $value): void
    {
        self::store()->put($key, $value);
    }

    public static function push(string $key, mixed $value): void
    {
        self::store()->push($key, $value);
    }

    public static function pull(string $key, mixed $default = null): mixed
    {
        return self::store()->pull($key, $default);
    }

    public static function forget(string ...$keys): void
    {
        self::store()->forget(...$keys);
    }

    /** @return array<string, mixed> */
    public static function all(): array
    {
        return self::store()->all();
    }

    public static function flash(string $key, mixed $value): void
    {
        self::store()->flash($key, $value);
    }

    public static function reflash(): void
    {
        self::store()->reflash();
    }

    public static function token(): string
    {
        return self::store()->token();
    }

    public static function regenerate(bool $destroy = true): void
    {
        self::store()->regenerate($destroy);
    }

    public static function invalidate(): void
    {
        self::store()->invalidate();
    }

    public static function id(): string
    {
        return self::store()->id();
    }
}
