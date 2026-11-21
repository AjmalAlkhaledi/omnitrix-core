<?php

declare(strict_types=1);

namespace Omnitrix\Notifications;

use Omnitrix\Database\ModelQuery;
use Omnitrix\Facades\Notification as NotificationFacade;

use function is_string;

/**
 * For models that receive notifications (`$user->notify(new InvoicePaid(42))`). The mail channel
 * sends to the `email` attribute (override routeNotificationForMail()); the database channel
 * stores rows in `notifications`.
 */
trait Notifiable
{
    public function notify(Notification $notification): void
    {
        NotificationFacade::send($this, $notification);
    }

    public function routeNotificationForMail(): ?string
    {
        $email = $this->getAttribute('email');

        return is_string($email) && $email !== '' ? $email : null;
    }

    /** @return ModelQuery<DatabaseNotification> */
    public function notifications(): ModelQuery
    {
        return DatabaseNotification::where('notifiable_type', static::class)->where('notifiable_id', $this->getKey())->latest();
    }

    /** @return ModelQuery<DatabaseNotification> */
    public function unreadNotifications(): ModelQuery
    {
        return $this->notifications()->whereNull('read_at');
    }
}
