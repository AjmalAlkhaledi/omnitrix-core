<?php

declare(strict_types=1);

namespace Omnitrix\Auth\Passwords;

use DateTimeImmutable;
use Omnitrix\Auth\Events\PasswordReset;
use Omnitrix\Auth\ModelUserProvider;
use Omnitrix\Contracts\Authenticatable;
use Omnitrix\Contracts\EventDispatcher;
use Omnitrix\Core\Database\Database;
use Omnitrix\Facades\Notification;

use function bin2hex;
use function hash_equals;
use function hash_hmac;
use function is_string;
use function method_exists;
use function random_bytes;
use function strtotime;
use function time;

/**
 * Password reset links: `sendResetLink()` stores a hashed token (one per address, in
 * `password_reset_tokens`) and e-mails the link; `reset()` checks the token and its age and
 * calls your callback to set the new password. Tokens are single use; new links for the same
 * address are throttled.
 */
final class PasswordBroker
{
    public const string RESET_LINK_SENT = 'passwords.sent';

    public const string PASSWORD_RESET = 'passwords.reset';

    public const string INVALID_USER = 'passwords.user';

    public const string INVALID_TOKEN = 'passwords.token';

    public const string RESET_THROTTLED = 'passwords.throttled';

    public function __construct(
        private readonly ModelUserProvider $users,
        private readonly Database $db,
        private readonly string $key,
        private readonly string $urlTemplate,
        private readonly string $table = 'password_reset_tokens',
        private readonly int $expireMinutes = 60,
        private readonly int $throttleSeconds = 60,
        private readonly ?EventDispatcher $events = null,
    ) {}

    /** @param array<string, mixed> $credentials usually `['email' => …]` */
    public function sendResetLink(array $credentials): string
    {
        $user = $this->users->retrieveByCredentials($credentials);
        if ($user === null) {
            return self::INVALID_USER;
        }
        $email = $this->emailOf($user);
        $existing = $this->db->table($this->table)->where('email', $email)->first();
        $created = is_string($existing['created_at'] ?? null) ? strtotime($existing['created_at']) : false;
        if ($created !== false && $created > time() - $this->throttleSeconds) {
            return self::RESET_THROTTLED;
        }
        $token = $this->createToken($email);
        Notification::send($user, new ResetPassword($token, $email, $this->urlTemplate, $this->expireMinutes));

        return self::RESET_LINK_SENT;
    }

    /** Creates (and stores) a new token for `$email`, replacing an older one. */
    public function createToken(string $email): string
    {
        $token = bin2hex(random_bytes(32));
        $this->db->table($this->table)->where('email', $email)->delete();
        $this->db->table($this->table)->insert(['email' => $email, 'token' => $this->hash($token), 'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s')]);

        return $token;
    }

    public function tokenExists(string $email, string $token): bool
    {
        $row = $this->db->table($this->table)->where('email', $email)->first();
        $stored = $row['token'] ?? null;
        $created = is_string($row['created_at'] ?? null) ? strtotime($row['created_at']) : false;

        return is_string($stored) && $created !== false && $created >= time() - $this->expireMinutes * 60 && hash_equals($stored, $this->hash($token));
    }

    /**
     * Resets the password when the token is valid: `$reset($user, $password)` stores it.
     *
     * @param array<string, mixed>                  $credentials `email`, `token`, `password`
     * @param callable(Authenticatable, string): void $reset
     */
    public function reset(array $credentials, callable $reset): string
    {
        $email = $credentials['email'] ?? null;
        $token = $credentials['token'] ?? null;
        $password = $credentials['password'] ?? null;
        if (!is_string($email) || !is_string($token) || !is_string($password)) {
            return self::INVALID_TOKEN;
        }
        $user = $this->users->retrieveByCredentials(['email' => $email]);
        if ($user === null) {
            return self::INVALID_USER;
        }
        if (!$this->tokenExists($email, $token)) {
            return self::INVALID_TOKEN;
        }
        $reset($user, $password);
        $this->db->table($this->table)->where('email', $email)->delete();
        $this->events?->dispatch(new PasswordReset($user));

        return self::PASSWORD_RESET;
    }

    private function hash(string $token): string
    {
        return hash_hmac('sha256', $token, $this->key);
    }

    private function emailOf(Authenticatable $user): string
    {
        $email = method_exists($user, 'getAttribute') ? $user->getAttribute('email') : null;

        return is_string($email) ? $email : '';
    }
}
