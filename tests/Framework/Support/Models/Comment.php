<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Support\Models;

use Omnitrix\Database\Model;
use Omnitrix\Database\Relations\BelongsTo;

/**
 * @property int            $id
 * @property string         $body
 * @property-read Post|null $post
 */
final class Comment extends Model
{
    protected static array $fillable = ['body'];

    /** @return BelongsTo<Post> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
