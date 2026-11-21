<?php

declare(strict_types=1);

namespace Omnitrix\Facades;

use Omnitrix\Contracts\Hasher;

/**
 * Password hashing (documentation: Security → Hashing).
 *
 * ```php
 * $hash = Hash::make($password);
 * if (Hash::check($password, $user->password) && Hash::needsRehash($user->password)) { ... }
 * ```
 */
final class Hash extends Facade
{
    public static function hasher(): Hasher
    {
        return self::resolve(Hasher::class);
    }

    public static function make(string $value): string
    {
        return self::hasher()->make($value);
    }

    public static function check(string $value, string $hash): bool
    {
        return self::hasher()->check($value, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return self::hasher()->needsRehash($hash);
    }
}
