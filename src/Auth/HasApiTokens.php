<?php

declare(strict_types=1);

namespace Omnitrix\Auth;

use DateTimeInterface;
use Omnitrix\Database\ModelQuery;

use function bin2hex;
use function hash;
use function random_bytes;

/**
 * API tokens for a user model:
 *
 * ```php
 * $token = $user->createToken('cli', ['orders:read'], new DateTimeImmutable('+30 days'));
 * return ['token' => $token->plainTextToken]; // shown once; sent back as "Authorization: Bearer …"
 * ```
 */
trait HasApiTokens
{
    /** @param list<string> $abilities */
    public function createToken(string $name, array $abilities = ['*'], ?DateTimeInterface $expiresAt = null): NewAccessToken
    {
        $secret = bin2hex(random_bytes(32));
        $token = PersonalAccessToken::create([
            'tokenable_type' => static::class,
            'tokenable_id' => $this->getKey(),
            'name' => $name,
            'token' => hash('sha256', $secret),
            'abilities' => $abilities,
            'expires_at' => $expiresAt,
        ]);

        return new NewAccessToken($token, $token->id . '|' . $secret);
    }

    /** @return ModelQuery<PersonalAccessToken> this user's tokens */
    public function tokens(): ModelQuery
    {
        return PersonalAccessToken::where('tokenable_type', static::class)->where('tokenable_id', $this->getKey());
    }

    /** The token that authenticated the current request, if any. */
    public function currentAccessToken(): ?PersonalAccessToken
    {
        $token = \Omnitrix\Core\Runtime\Context\ContextStorage::current()?->get(TokenGuard::CURRENT_TOKEN);

        return $token instanceof PersonalAccessToken ? $token : null;
    }

    /** Whether the current request's token grants `$ability` (true for session logins). */
    public function tokenCan(string $ability): bool
    {
        $token = $this->currentAccessToken();

        return $token === null || $token->can($ability);
    }
}
