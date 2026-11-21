<?php

declare(strict_types=1);

namespace Omnitrix\Notifications;

use Omnitrix\Database\Model;
use PHPUnit\Framework\Assert;

use function count;
use function is_iterable;
use function is_object;

/** Test double installed by `Notification::fake()`. */
final class NotificationFake
{
    /** @var list<array{object, Notification}> */
    private array $sent = [];

    /** @param iterable<object>|object $notifiables */
    public function record(iterable|object $notifiables, Notification $notification): void
    {
        foreach (is_iterable($notifiables) ? $notifiables : [$notifiables] as $notifiable) {
            if (!is_object($notifiable)) {
                continue;
            }
            $this->sent[] = [$notifiable, $notification];
        }
    }

    /**
     * @param class-string<Notification>                 $notification
     * @param (callable(Notification, object): bool)|null $filter
     *
     * @return list<Notification>
     */
    public function sent(string $notification, ?callable $filter = null): array
    {
        $matches = [];
        foreach ($this->sent as [$notifiable, $sent]) {
            if ($sent instanceof $notification && ($filter === null || $filter($sent, $notifiable) === true)) {
                $matches[] = $sent;
            }
        }

        return $matches;
    }

    /** @param class-string<Notification> $notification */
    public function assertSentTo(object $notifiable, string $notification, ?callable $filter = null): void
    {
        $found = $this->sent($notification, static fn(Notification $n, object $to): bool => self::same($to, $notifiable) && ($filter === null || $filter($n) === true));
        Assert::assertNotSame([], $found, "{$notification} was not sent to the notifiable");
    }

    /** @param class-string<Notification> $notification */
    public function assertNotSentTo(object $notifiable, string $notification): void
    {
        Assert::assertSame([], $this->sent($notification, static fn(Notification $n, object $to): bool => self::same($to, $notifiable)), "{$notification} was sent");
    }

    public function assertCount(int $expected): void
    {
        Assert::assertSame($expected, count($this->sent));
    }

    private static function same(object $a, object $b): bool
    {
        if ($a instanceof Model && $b instanceof Model) {
            return $a::class === $b::class && $a->getKey() === $b->getKey();
        }

        return $a === $b;
    }
}
