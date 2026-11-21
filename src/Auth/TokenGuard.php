<?php

declare(strict_types=1);

namespace Omnitrix\Auth;

use DateTimeImmutable;
use DateTimeInterface;
use Omnitrix\Contracts\Authenticatable;
use Omnitrix\Contracts\Guard;
use Omnitrix\Core\Runtime\Context\ContextStorage;
use Omnitrix\Http\Request;

use function ctype_digit;
use function explode;
use function hash;
use function hash_equals;
use function str_contains;

/**
 * API authentication with personal access tokens (`Authorization: Bearer {id}|{secret}`), created
 * with {@see HasApiTokens::createToken()}. Expired tokens are rejected; `last_used_at` is updated
 * at most once a minute.
 */
final class TokenGuard implements Guard
{
    public const string CURRENT_TOKEN = 'auth.current_token';

    public function __construct(private readonly string $name, private readonly ModelUserProvider $provider) {}

    public function user(): ?Authenticatable
    {
        $context = ContextStorage::current();
        if ($context === null) {
            return null;
        }
        $cached = $context->get($this->cacheKey(), false);
        if ($cached instanceof Authenticatable || $cached === null) {
            return $cached;
        }
        [$user, $token] = $this->resolve(Request::current()?->bearerToken());
        $context->set($this->cacheKey(), $user);
        if ($token !== null) {
            $context->set(self::CURRENT_TOKEN, $token);
        }

        return $user;
    }

    public function id(): int|string|null
    {
        return $this->user()?->getAuthIdentifier();
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return !$this->check();
    }

    public function validate(array $credentials): bool
    {
        $user = $this->provider->retrieveByCredentials($credentials);

        return $user !== null && $this->provider->validateCredentials($user, $credentials);
    }

    public function setUser(?Authenticatable $user): void
    {
        ContextStorage::current()?->set($this->cacheKey(), $user);
    }

    /** The token that authenticated the request. */
    public function token(): ?PersonalAccessToken
    {
        $this->user();
        $token = ContextStorage::current()?->get(self::CURRENT_TOKEN);

        return $token instanceof PersonalAccessToken ? $token : null;
    }

    /** @return array{?Authenticatable, ?PersonalAccessToken} */
    private function resolve(?string $bearer): array
    {
        if ($bearer === null || !str_contains($bearer, '|')) {
            return [null, null];
        }
        [$id, $secret] = explode('|', $bearer, 2);
        if (!ctype_digit($id) || $secret === '') {
            return [null, null];
        }
        $token = PersonalAccessToken::find((int) $id);
        if ($token === null || !hash_equals($token->token, hash('sha256', $secret)) || $token->isExpired() || $token->tokenable_type !== $this->provider->model()) {
            return [null, null];
        }
        $user = $this->provider->retrieveById($token->tokenable_id);
        if ($user === null) {
            return [null, null];
        }
        $last = $token->last_used_at;
        if (!$last instanceof DateTimeInterface || $last < new DateTimeImmutable('-1 minute')) {
            $token->last_used_at = new DateTimeImmutable();
            $token->save();
        }

        return [$user, $token];
    }

    private function cacheKey(): string
    {
        return 'auth.user.' . $this->name;
    }
}
