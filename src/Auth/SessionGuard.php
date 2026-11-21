<?php

declare(strict_types=1);

namespace Omnitrix\Auth;

use LogicException;
use Omnitrix\Contracts\Authenticatable;
use Omnitrix\Contracts\Guard;
use Omnitrix\Core\Runtime\Context\ContextStorage;
use Omnitrix\Database\Model;
use Omnitrix\Http\Cookie;
use Omnitrix\Http\CookieJar;
use Omnitrix\Http\Request;
use Omnitrix\Session\StartSession;
use Omnitrix\Session\Store;

use function bin2hex;
use function explode;
use function hash;
use function hash_equals;
use function is_int;
use function is_string;
use function random_bytes;
use function str_contains;
use function time;

/**
 * Session-based login for browser clients (needs {@see StartSession}). `attempt()` checks the
 * credentials, regenerates the session id and stores the user id; `$remember` also sets a
 * long-lived cookie (`id|token`, the token stored hashed in `remember_token`) that logs the
 * user in again when the session has expired.
 */
final class SessionGuard implements Guard
{
    public function __construct(private readonly string $name, private readonly ModelUserProvider $provider, private readonly int $rememberDays = 30) {}

    public function user(): ?Authenticatable
    {
        $context = ContextStorage::current();
        if ($context === null) {
            return null;
        }
        $cached = $context->get($this->cacheKey(), false);
        if ($cached instanceof Authenticatable) {
            return $cached;
        }
        if ($cached === null) {
            return null; // resolved before: nobody
        }
        $user = null;
        $session = $this->session();
        $id = $session?->get($this->sessionKey());
        if (is_int($id) || is_string($id)) {
            $user = $this->provider->retrieveById($id);
        }
        if ($user === null) {
            $user = $this->userFromRememberCookie();
            if ($user !== null && $session !== null) {
                $session->regenerate();
                $session->put($this->sessionKey(), $user->getAuthIdentifier());
            }
        }
        $context->set($this->cacheKey(), $user);

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

    /** @param array<string, mixed> $credentials e.g. `['email' => …, 'password' => …]` */
    public function attempt(array $credentials, bool $remember = false): bool
    {
        $user = $this->provider->retrieveByCredentials($credentials);
        if ($user === null || !$this->provider->validateCredentials($user, $credentials)) {
            return false;
        }
        $this->login($user, $remember);

        return true;
    }

    public function login(Authenticatable $user, bool $remember = false): void
    {
        $session = $this->requireSession();
        $session->regenerate(); // a new id at login prevents session fixation
        $session->put($this->sessionKey(), $user->getAuthIdentifier());
        if ($remember) {
            $token = bin2hex(random_bytes(30));
            $user->setRememberToken(hash('sha256', $token));
            if ($user instanceof Model) {
                $user->save();
            }
            CookieJar::queue(new Cookie($this->rememberCookie(), $user->getAuthIdentifier() . '|' . $token, time() + $this->rememberDays * 86400));
        }
        $this->setUser($user);
    }

    public function loginUsingId(int|string $id, bool $remember = false): ?Authenticatable
    {
        $user = $this->provider->retrieveById($id);
        if ($user !== null) {
            $this->login($user, $remember);
        }

        return $user;
    }

    /** Logs out: the session is invalidated and the remember token revoked. */
    public function logout(): void
    {
        $user = $this->user();
        if ($user !== null && $user->getRememberToken() !== null) {
            $user->setRememberToken(null);
            if ($user instanceof Model) {
                $user->save();
            }
        }
        $this->session()?->invalidate();
        CookieJar::queue(Cookie::forget($this->rememberCookie()));
        $this->setUser(null);
    }

    public function setUser(?Authenticatable $user): void
    {
        ContextStorage::current()?->set($this->cacheKey(), $user);
    }

    private function userFromRememberCookie(): ?Authenticatable
    {
        $value = Request::current()?->cookie($this->rememberCookie());
        if (!is_string($value) || !str_contains($value, '|')) {
            return null;
        }
        [$id, $token] = explode('|', $value, 2);
        $user = $id === '' ? null : $this->provider->retrieveById($id);
        $stored = $user?->getRememberToken();

        return $stored !== null && hash_equals($stored, hash('sha256', $token)) ? $user : null;
    }

    private function session(): ?Store
    {
        $store = ContextStorage::current()?->get(StartSession::ATTRIBUTE);

        return $store instanceof Store ? $store : null;
    }

    private function requireSession(): Store
    {
        return $this->session() ?? throw new LogicException("The '{$this->name}' guard needs the StartSession middleware on the route");
    }

    private function sessionKey(): string
    {
        return 'login_' . $this->name;
    }

    private function rememberCookie(): string
    {
        return 'remember_' . $this->name;
    }

    private function cacheKey(): string
    {
        return 'auth.user.' . $this->name;
    }
}
