<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Support\Models;

use DateTimeImmutable;
use Omnitrix\Database\Collection;
use Omnitrix\Database\Model;
use Omnitrix\Database\Relations\HasMany;
use Omnitrix\Database\Relations\HasOne;

/**
 * @property int                    $id
 * @property string                 $name
 * @property string                 $email
 * @property bool                   $is_admin
 * @property array<mixed>|null      $settings
 * @property DateTimeImmutable|null $created_at
 * @property-read Collection<Post>  $posts
 * @property-read Profile|null      $profile
 */
final class User extends Model
{
    protected static array $fillable = ['name', 'email', 'password', 'is_admin', 'settings'];

    protected static array $hidden = ['password'];

    protected static array $casts = ['is_admin' => 'bool', 'settings' => 'array'];

    /** @return HasMany<Post> */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /** @return HasOne<Profile> */
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }
}
