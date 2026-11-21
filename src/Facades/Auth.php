<?php

declare(strict_types=1);

namespace Omnitrix\Facades;

use LogicException;
use Omnitrix\Auth\AuthManager;
use Omnitrix\Auth\SessionGuard;
use Omnitrix\Auth\TokenGuard;
use Omnitrix\Contracts\Authenticatable;
use Omnitrix\Contracts\Guard;
use Omnitrix\Http\Exception\HttpException;

/**
 * Authentication (documentation: Security → Authentication).
 *
 * ```php
 * if (Auth::guard('web')->attempt(['email' => $email, 'password' => $password], remember: true)) { … }
 * $user = Auth::user();          // default guard
 * Auth::guard('web')->logout();
 * ```
 */
final class Auth extends Facade
{
    public static function manager(): AuthManager
    {
        return self::resolve(AuthManager::class);
    }

    public static function guard(?string $name = null): Guard
    {
        return self::manager()->guard($name);
    }

    public static function user(): ?Authenticatable
    {
        return self::guard()->user();
    }

    /** The user, or a 401 error. */
    public static function authenticate(): Authenticatable
    {
        return self::user() ?? throw new HttpException(401, 'Unauthenticated');
    }

    public static function id(): int|string|null
    {
        return self::guard()->id();
    }

    public static function check(): bool
    {
        return self::guard()->check();
    }

    public static function guest(): bool
    {
        return self::guard()->guest();
    }

    /** @param array<string, mixed> $credentials */
    public static function validate(array $credentials): bool
    {
        return self::guard()->validate($credentials);
    }

    /** @param array<string, mixed> $credentials */
    public static function attempt(array $credentials, bool $remember = false): bool
    {
        return self::sessionGuard()->attempt($credentials, $remember);
    }

    public static function login(Authenticatable $user, bool $remember = false): void
    {
        self::sessionGuard()->login($user, $remember);
    }

    public static function logout(): void
    {
        self::sessionGuard()->logout();
    }

    private static function sessionGuard(): SessionGuard
    {
        $guard = self::guard();
        if ($guard instanceof SessionGuard) {
            return $guard;
        }
        foreach (self::manager()->guardNames() as $name) {
            $candidate = self::manager()->guard($name);
            if ($candidate instanceof SessionGuard) {
                return $candidate;
            }
        }
        throw new LogicException('No session guard is configured (login and logout are for session guards; API clients use tokens)');
    }

    /** The token guard's current token, if the request used one. */
    public static function token(): ?\Omnitrix\Auth\PersonalAccessToken
    {
        $guard = self::guard();

        return $guard instanceof TokenGuard ? $guard->token() : null;
    }
}
