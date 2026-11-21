<?php

declare(strict_types=1);

namespace Omnitrix\Facades;

use LogicException;
use Omnitrix\Core\Container\Container;
use Omnitrix\Notifications\AnonymousNotifiable;
use Omnitrix\Notifications\Notification as BaseNotification;
use Omnitrix\Notifications\NotificationFake;
use Omnitrix\Notifications\NotificationSender;

/**
 * Notifications (documentation: Async & services → Notifications).
 *
 * ```php
 * Notification::send($users, new InvoicePaid(42));
 * Notification::route('mail', 'ops@example.com')->notify(new DiskFull());
 * ```
 */
final class Notification extends Facade
{
    public static function sender(): NotificationSender
    {
        return self::resolve(NotificationSender::class);
    }

    /** @param iterable<object>|object $notifiables */
    public static function send(iterable|object $notifiables, BaseNotification $notification): void
    {
        $fake = self::fakeInstance();
        if ($fake !== null) {
            $fake->record($notifiables, $notification);

            return;
        }
        self::sender()->send($notifiables, $notification);
    }

    public static function route(string $channel, string $address): AnonymousNotifiable
    {
        return (new AnonymousNotifiable())->route($channel, $address);
    }

    /** Records notifications instead of sending them, in this worker (tests). */
    public static function fake(): NotificationFake
    {
        $container = self::container();
        if (!$container instanceof Container) {
            throw new LogicException('Notification::fake() needs the application container (inside withServices())');
        }
        $fake = self::fakeInstance() ?? new NotificationFake();
        $container->instance(NotificationFake::class, $fake);

        return $fake;
    }

    private static function fakeInstance(): ?NotificationFake
    {
        $container = self::container();
        $fake = $container !== null && $container->has(NotificationFake::class) ? $container->get(NotificationFake::class) : null;

        return $fake instanceof NotificationFake ? $fake : null;
    }
}
