<?php

declare(strict_types=1);

namespace Omnitrix\Facades;

use Omnitrix\Auth\Access\AuthorizationResponse;
use Omnitrix\Auth\Access\Gate as AccessGate;
use Omnitrix\Contracts\Authenticatable;

/**
 * Authorization (documentation: Security → Authorization). Define abilities and policies in
 * `bootstrap.php` with `$app->define()` / `$app->policy()` (or `auth.policies`).
 *
 * ```php
 * Gate::allows('update', $post);
 * Gate::authorize('delete', $post);   // 403 when denied
 * ```
 */
final class Gate extends Facade
{
    public static function gate(): AccessGate
    {
        return self::resolve(AccessGate::class);
    }

    public static function allows(string $ability, mixed ...$arguments): bool
    {
        return self::gate()->allows($ability, ...$arguments);
    }

    public static function denies(string $ability, mixed ...$arguments): bool
    {
        return self::gate()->denies($ability, ...$arguments);
    }

    public static function authorize(string $ability, mixed ...$arguments): AuthorizationResponse
    {
        return self::gate()->authorize($ability, ...$arguments);
    }

    public static function inspect(string $ability, mixed ...$arguments): AuthorizationResponse
    {
        return self::gate()->inspect($ability, ...$arguments);
    }

    /** @param list<string> $abilities */
    public static function any(array $abilities, mixed ...$arguments): bool
    {
        return self::gate()->any($abilities, ...$arguments);
    }

    public static function forUser(?Authenticatable $user): AccessGate
    {
        return self::gate()->forUser($user);
    }

    /** @param callable $callback returns bool or AuthorizationResponse; defined in this worker only */
    public static function define(string $ability, callable $callback): void
    {
        self::gate()->define($ability, $callback);
    }
}
