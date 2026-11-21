<?php

declare(strict_types=1);

namespace Omnitrix\Facades;

use Omnitrix\Auth\Passwords\PasswordBroker;
use Omnitrix\Contracts\Authenticatable;

/**
 * Password reset (documentation: Security → Authentication).
 *
 * ```php
 * $status = Password::sendResetLink(['email' => $email]);
 * $status = Password::reset($request->all(), function (User $user, string $password) { $user->update(['password' => $password]); });
 * ```
 */
final class Password extends Facade
{
    public const string RESET_LINK_SENT = PasswordBroker::RESET_LINK_SENT;

    public const string PASSWORD_RESET = PasswordBroker::PASSWORD_RESET;

    public const string INVALID_USER = PasswordBroker::INVALID_USER;

    public const string INVALID_TOKEN = PasswordBroker::INVALID_TOKEN;

    public const string RESET_THROTTLED = PasswordBroker::RESET_THROTTLED;

    public static function broker(): PasswordBroker
    {
        return self::resolve(PasswordBroker::class);
    }

    /** @param array<string, mixed> $credentials */
    public static function sendResetLink(array $credentials): string
    {
        return self::broker()->sendResetLink($credentials);
    }

    /**
     * @param array<string, mixed>                  $credentials
     * @param callable(Authenticatable, string): void $reset
     */
    public static function reset(array $credentials, callable $reset): string
    {
        return self::broker()->reset($credentials, $reset);
    }

    public static function createToken(string $email): string
    {
        return self::broker()->createToken($email);
    }
}
