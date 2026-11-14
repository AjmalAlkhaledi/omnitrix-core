<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Support\Models;

use Omnitrix\Database\Collection;
use Omnitrix\Database\Model;
use Omnitrix\Database\Relations\BelongsToMany;

/**
 * @property int                   $id
 * @property string                $name
 * @property-read Collection<Post> $posts
 */
final class Tag extends Model
{
    protected static array $fillable = ['name'];

    protected static bool $timestamps = false;

    /** @return BelongsToMany<Post> */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class);
    }
}
