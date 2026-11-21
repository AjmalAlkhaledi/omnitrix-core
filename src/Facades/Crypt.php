<?php

declare(strict_types=1);

namespace Omnitrix\Facades;

use Omnitrix\Contracts\Encrypter;

/**
 * Encryption with the application key (documentation: Security → Encryption). Needs `app.key`.
 *
 * ```php
 * $token = Crypt::encrypt(['user' => 10]);
 * $data = Crypt::decrypt($token); // DecryptException when changed or unreadable
 * ```
 */
final class Crypt extends Facade
{
    public static function encrypter(): Encrypter
    {
        return self::resolve(Encrypter::class);
    }

    public static function encrypt(mixed $value): string
    {
        return self::encrypter()->encrypt($value);
    }

    public static function decrypt(string $payload): mixed
    {
        return self::encrypter()->decrypt($payload);
    }

    public static function encryptString(string $value): string
    {
        return self::encrypter()->encryptString($value);
    }

    public static function decryptString(string $payload): string
    {
        return self::encrypter()->decryptString($payload);
    }
}
