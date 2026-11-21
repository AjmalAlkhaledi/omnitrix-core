<?php

declare(strict_types=1);

namespace Omnitrix\Notifications;

use Omnitrix\Facades\Notification as NotificationFacade;

/** A recipient that is not a model: `Notification::route('mail', 'ops@example.com')->notify(...)`. */
final class AnonymousNotifiable
{
    /** @var array<string, string> */
    public array $routes = [];

    public function route(string $channel, string $address): self
    {
        $this->routes[$channel] = $address;

        return $this;
    }

    public function routeNotificationForMail(): ?string
    {
        return $this->routes['mail'] ?? null;
    }

    public function notify(Notification $notification): void
    {
        NotificationFacade::send($this, $notification);
    }
}
