<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Support\Models;

use Omnitrix\Database\Collection;
use Omnitrix\Database\Model;
use Omnitrix\Database\Relations\BelongsTo;
use Omnitrix\Database\Relations\BelongsToMany;
use Omnitrix\Database\Relations\HasMany;

/**
 * @property int                      $id
 * @property int                      $user_id
 * @property string                   $title
 * @property bool                     $published
 * @property-read User|null           $user
 * @property-read Collection<Tag>     $tags
 * @property-read Collection<Comment> $comments
 */
final class Post extends Model
{
    protected static array $fillable = ['title', 'published'];

    protected static array $casts = ['published' => 'bool'];

    /** @return BelongsTo<User> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsToMany<Tag> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /** @return HasMany<Comment> */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }
}
