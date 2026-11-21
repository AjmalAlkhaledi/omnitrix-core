<?php

declare(strict_types=1);

namespace Omnitrix\Auth;

use DateTimeImmutable;
use DateTimeInterface;
use Omnitrix\Database\Model;

use function in_array;
use function is_array;

/**
 * An API token of a user (`personal_access_tokens` table). Only the SHA-256 hash of the token is
 * stored; the plain token is shown once, when created.
 *
 * @property int                    $id
 * @property string                 $tokenable_type
 * @property int|string             $tokenable_id
 * @property string                 $name
 * @property string                 $token
 * @property list<string>|null      $abilities
 * @property DateTimeInterface|null $last_used_at
 * @property DateTimeInterface|null $expires_at
 */
final class PersonalAccessToken extends Model
{
    protected static ?string $table = 'personal_access_tokens';

    /** @var list<string> */
    protected static array $fillable = ['tokenable_type', 'tokenable_id', 'name', 'token', 'abilities', 'expires_at'];

    /** @var list<string> */
    protected static array $hidden = ['token'];

    /** @var array<string, string> */
    protected static array $casts = ['abilities' => 'array', 'last_used_at' => 'datetime', 'expires_at' => 'datetime'];

    /** Whether the token grants `$ability` (`*` grants everything). */
    public function can(string $ability): bool
    {
        $abilities = $this->abilities;

        return is_array($abilities) && (in_array('*', $abilities, true) || in_array($ability, $abilities, true));
    }

    public function cant(string $ability): bool
    {
        return !$this->can($ability);
    }

    public function isExpired(): bool
    {
        return $this->expires_at instanceof DateTimeInterface && $this->expires_at < new DateTimeImmutable();
    }
}
