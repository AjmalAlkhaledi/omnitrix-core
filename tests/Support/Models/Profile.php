<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Support\Models;

use Omnitrix\Database\Model;
use Omnitrix\Database\Relations\BelongsTo;

/**
 * @property int            $id
 * @property int            $user_id
 * @property string|null    $bio
 * @property-read User|null $user
 */
final class Profile extends Model
{
    protected static array $fillable = ['bio'];

    protected static bool $timestamps = false;

    /** @return BelongsTo<User> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
