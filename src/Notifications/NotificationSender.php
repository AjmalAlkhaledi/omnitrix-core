<?php

declare(strict_types=1);

namespace Omnitrix\Notifications;

use Closure;
use InvalidArgumentException;
use LogicException;
use Omnitrix\Core\Support\Id;
use Omnitrix\Database\Model;
use Omnitrix\Events\ShouldQueue;
use Omnitrix\Mail\Address;
use Omnitrix\Mail\Mailable;
use Omnitrix\Mail\Mailer;

use function base64_decode;
use function base64_encode;
use function class_exists;
use function is_array;
use function is_int;
use function is_iterable;
use function is_object;
use function is_string;
use function is_subclass_of;
use function method_exists;
use function serialize;
use function unserialize;

/** Delivers notifications to their channels, now or through the queue. */
final class NotificationSender
{
    public const string QUEUED_NOTIFICATION_JOB = 'omnitrix.notification';

    /** @param (Closure(string, array<string, mixed>, float, ?string): mixed)|null $queue */
    public function __construct(private readonly ?Mailer $mailer, private readonly ?Closure $queue = null) {}

    /** @param iterable<object>|object $notifiables */
    public function send(iterable|object $notifiables, Notification $notification): void
    {
        foreach (is_iterable($notifiables) ? $notifiables : [$notifiables] as $notifiable) {
            if (!is_object($notifiable)) {
                continue;
            }
            if ($notification instanceof ShouldQueue && $this->queue !== null) {
                ($this->queue)(self::QUEUED_NOTIFICATION_JOB, ['notifiable' => self::freeze($notifiable), 'notification' => base64_encode(serialize($notification))], 0.0, $notification->queue);
                continue;
            }
            $this->sendNow($notifiable, $notification);
        }
    }

    public function sendNow(object $notifiable, Notification $notification): void
    {
        foreach ($notification->via($notifiable) as $channel) {
            match ($channel) {
                'mail' => $this->mail($notifiable, $notification),
                'database' => $this->database($notifiable, $notification),
                default => throw new InvalidArgumentException("Unknown notification channel '{$channel}'"),
            };
        }
    }

    /**
     * Sends a queued notification (the handler of {@see QUEUED_NOTIFICATION_JOB}).
     *
     * @param array<string, mixed> $payload
     */
    public function handleQueued(array $payload): void
    {
        $data = is_string($payload['notification'] ?? null) ? base64_decode($payload['notification'], true) : false;
        $notification = is_string($data) ? unserialize($data) : null; // written by this application only
        $notifiable = self::thaw($payload['notifiable'] ?? null);
        if (!$notification instanceof Notification) {
            throw new InvalidArgumentException('Invalid queued notification payload');
        }
        if ($notifiable !== null) { // a deleted model is skipped
            $this->sendNow($notifiable, $notification);
        }
    }

    private function mail(object $notifiable, Notification $notification): void
    {
        if ($this->mailer === null) {
            throw new LogicException('The mail channel needs mail configuration (config/mail.php)');
        }
        $address = method_exists($notifiable, 'routeNotificationForMail') ? $notifiable->routeNotificationForMail() : null;
        if (!is_string($address) || $address === '') {
            return; // nowhere to send
        }
        $mail = $notification->toMail($notifiable);
        if ($mail instanceof Mailable) {
            $this->mailer->send($mail, $address);

            return;
        }
        if ($mail->getTo() === []) {
            $parsed = Address::from($address);
            $mail->to($parsed->email, $parsed->name);
        }
        $this->mailer->send($mail);
    }

    private function database(object $notifiable, Notification $notification): void
    {
        if (!$notifiable instanceof Model) {
            throw new InvalidArgumentException('The database channel needs a model notifiable');
        }
        DatabaseNotification::create([
            'id' => Id::generate(),
            'type' => $notification::class,
            'notifiable_type' => $notifiable::class,
            'notifiable_id' => $notifiable->getKey(),
            'data' => $notification->toArray($notifiable),
        ]);
    }

    /** @return array<string, mixed> models by class and key (reloaded in the worker), others serialized */
    private static function freeze(object $notifiable): array
    {
        if ($notifiable instanceof Model) {
            return ['model' => $notifiable::class, 'key' => $notifiable->getKey()];
        }

        return ['object' => base64_encode(serialize($notifiable))];
    }

    private static function thaw(mixed $frozen): ?object
    {
        if (!is_array($frozen)) {
            return null;
        }
        $class = $frozen['model'] ?? null;
        $key = $frozen['key'] ?? null;
        if (is_string($class) && class_exists($class) && is_subclass_of($class, Model::class) && (is_int($key) || is_string($key))) {
            return $class::find($key);
        }
        $data = is_string($frozen['object'] ?? null) ? base64_decode($frozen['object'], true) : false;
        $object = is_string($data) ? unserialize($data) : null;

        return is_object($object) ? $object : null;
    }
}
