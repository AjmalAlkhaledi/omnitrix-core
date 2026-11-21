<?php

declare(strict_types=1);

namespace Omnitrix\Broadcasting;

use Closure;
use Omnitrix\Contracts\Authenticatable;

use function array_map;
use function array_slice;
use function get_object_vars;
use function is_array;
use function is_string;
use function method_exists;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function str_starts_with;
use function strlen;
use function strrchr;
use function substr;

/**
 * Broadcasting: publishes events and authorizes listeners of private and presence channels.
 *
 * ```php
 * $app->broadcastChannel('user.{id}', fn (User $user, string $id) => (string) $user->id === $id);
 * $app->broadcastChannel('chat.{room}', fn (User $user, string $room) => ['id' => $user->id, 'name' => $user->name]); // presence: member info
 * ```
 */
final class BroadcastManager
{
    /** @var array<string, Closure> pattern => authorizer */
    private array $channels = [];

    public function __construct(private readonly Broadcaster $broadcaster) {}

    public function broadcaster(): Broadcaster
    {
        return $this->broadcaster;
    }

    /** @param callable $callback receives the user and the pattern's parameters; returns bool or member info */
    public function channel(string $pattern, callable $callback): void
    {
        $this->channels[$pattern] = $callback(...);
    }

    /** Publishes an event implementing ShouldBroadcast. */
    public function broadcastEvent(ShouldBroadcast $event): void
    {
        $channels = $event->broadcastOn();
        $names = array_map(static fn(Channel $c): string => $c->name, is_array($channels) ? $channels : [$channels]);
        $name = method_exists($event, 'broadcastAs') ? $event->broadcastAs() : null;
        $name = is_string($name) && $name !== '' ? $name : substr((string) strrchr('\\' . $event::class, '\\'), 1);
        $data = method_exists($event, 'broadcastWith') ? $event->broadcastWith() : get_object_vars($event);
        $this->broadcaster->publish($names, $name, self::stringKeys(is_array($data) ? $data : []));
    }

    /**
     * Publishes data without an event class.
     *
     * @param Channel|list<Channel> $channels
     * @param array<string, mixed>  $data
     */
    public function send(Channel|array $channels, string $event, array $data = []): void
    {
        $this->broadcaster->publish(array_map(static fn(Channel $c): string => $c->name, is_array($channels) ? $channels : [$channels]), $event, $data);
    }

    /**
     * Whether `$user` may listen to `$channel` (a public channel: always); for presence channels
     * the member info is returned instead of true.
     *
     * @return bool|array<string, mixed>
     */
    public function authorize(?Authenticatable $user, string $channel): bool|array
    {
        $prefix = str_starts_with($channel, PresenceChannel::PREFIX) ? PresenceChannel::PREFIX : (str_starts_with($channel, PrivateChannel::PREFIX) ? PrivateChannel::PREFIX : '');
        if ($prefix === '') {
            return true;
        }
        if ($user === null) {
            return false;
        }
        $name = substr($channel, strlen($prefix));
        foreach ($this->channels as $pattern => $callback) {
            $regex = '#^' . preg_replace('/\\\{([A-Za-z_][A-Za-z0-9_]*)\\\}/', '([^.]+)', preg_quote($pattern, '#')) . '$#';
            if (preg_match($regex, $name, $m) === 1) {
                $result = $callback($user, ...array_slice($m, 1));
                if ($prefix === PresenceChannel::PREFIX) {
                    return is_array($result) ? self::stringKeys($result) : ($result === true ? ['id' => $user->getAuthIdentifier()] : false);
                }

                return $result === true || is_array($result);
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function stringKeys(array $data): array
    {
        $typed = [];
        foreach ($data as $key => $value) {
            $typed[(string) $key] = $value;
        }

        return $typed;
    }
}
