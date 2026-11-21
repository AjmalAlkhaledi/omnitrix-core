<?php

declare(strict_types=1);

namespace Omnitrix\Auth;

use function is_string;

/**
 * Implements {@see \Omnitrix\Contracts\Authenticatable} for models with `password` and
 * `remember_token` columns:
 *
 * ```php
 * final class User extends Model implements \Omnitrix\Contracts\Authenticatable
 * {
 *     use Authenticatable;
 *     protected static array $hidden = ['password', 'remember_token'];
 *     protected static array $casts = ['password' => 'hashed'];
 * }
 * ```
 */
trait Authenticatable
{
    public function getAuthIdentifierName(): string
    {
        return static::keyName();
    }

    public function getAuthIdentifier(): int|string|null
    {
        return $this->getKey();
    }

    public function getAuthPassword(): string
    {
        $password = $this->getAttribute('password');

        return is_string($password) ? $password : '';
    }

    public function getRememberToken(): ?string
    {
        $token = $this->getAttribute('remember_token');

        return is_string($token) ? $token : null;
    }

    public function setRememberToken(?string $token): void
    {
        $this->setAttribute('remember_token', $token);
    }
}
