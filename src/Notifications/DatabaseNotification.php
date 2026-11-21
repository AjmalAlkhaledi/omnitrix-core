<?php

declare(strict_types=1);

namespace Omnitrix\Notifications;

use DateTimeImmutable;
use DateTimeInterface;
use Omnitrix\Database\Model;

/**
 * A notification stored by the database channel (`notifications` table).
 *
 * @property string                  $id
 * @property string                  $type
 * @property string                  $notifiable_type
 * @property int|string              $notifiable_id
 * @property array<string, mixed>    $data
 * @property DateTimeInterface|null  $read_at
 */
final class DatabaseNotification extends Model
{
    protected static ?string $table = 'notifications';

    protected static bool $incrementing = false;

    /** @var list<string> */
    protected static array $fillable = ['id', 'type', 'notifiable_type', 'notifiable_id', 'data', 'read_at'];

    /** @var array<string, string> */
    protected static array $casts = ['data' => 'array', 'read_at' => 'datetime'];

    public function markAsRead(): void
    {
        if ($this->read_at === null) {
            $this->read_at = new DateTimeImmutable();
            $this->save();
        }
    }

    public function markAsUnread(): void
    {
        $this->read_at = null;
        $this->save();
    }

    public function read(): bool
    {
        return $this->read_at !== null;
    }
}
