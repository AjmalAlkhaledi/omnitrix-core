<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Unit\Broadcasting;

use InvalidArgumentException;
use Omnitrix\Broadcasting\ArrayBroadcaster;
use Omnitrix\Broadcasting\BroadcastManager;
use Omnitrix\Broadcasting\Channel;
use Omnitrix\Broadcasting\PresenceChannel;
use Omnitrix\Broadcasting\PrivateChannel;
use Omnitrix\Broadcasting\ShouldBroadcast;
use Omnitrix\Events\Dispatcher;
use Omnitrix\Tests\Support\TestCase;
use Omnitrix\Tests\Unit\Auth\GateUser;
use PHPUnit\Framework\Attributes\CoversClass;

final class InvoicePaid implements ShouldBroadcast
{
    public function __construct(public int $invoice, private string $secret = 'hidden') {}

    public function secret(): string
    {
        return $this->secret; // private: not broadcast
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.1'), new Channel('billing')];
    }
}

final class Renamed implements ShouldBroadcast
{
    public function broadcastOn(): Channel
    {
        return new Channel('ops');
    }

    public function broadcastAs(): string
    {
        return 'ops.alert';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['level' => 'high'];
    }
}

#[CoversClass(BroadcastManager::class)]
#[CoversClass(Channel::class)]
final class BroadcastManagerTest extends TestCase
{
    public function testEventsArePublishedThroughTheDispatcher(): void
    {
        $broadcaster = new ArrayBroadcaster();
        $manager = new BroadcastManager($broadcaster);
        $events = new Dispatcher(null, null, null, $manager->broadcastEvent(...));
        $events->dispatch(new InvoicePaid(7));
        $events->dispatch(new Renamed());
        self::assertSame([
            [['private-user.1', 'billing'], 'InvoicePaid', ['invoice' => 7]],
            [['ops'], 'ops.alert', ['level' => 'high']],
        ], $broadcaster->published, 'public properties by default; broadcastAs/With override');
        $other = new ArrayBroadcaster();
        (new BroadcastManager($other))->send(new PresenceChannel('room.1'), 'typing', ['user' => 1]);
        self::assertSame([[['presence-room.1'], 'typing', ['user' => 1]]], $other->published);
    }

    public function testChannelAuthorization(): void
    {
        $manager = new BroadcastManager(new ArrayBroadcaster());
        $manager->channel('user.{id}', static fn(GateUser $u, string $id): bool => (string) $u->id === $id);
        $manager->channel('team.{team}.chat', static fn(GateUser $u, string $team): array => ['id' => $u->id, 'team' => $team]);
        $ann = new GateUser(1);
        self::assertTrue($manager->authorize(null, 'news'), 'public channels need nobody');
        self::assertFalse($manager->authorize(null, 'private-user.1'), 'private channels need a user');
        self::assertTrue($manager->authorize($ann, 'private-user.1'));
        self::assertFalse($manager->authorize($ann, 'private-user.2'));
        self::assertFalse($manager->authorize($ann, 'private-unknown.1'), 'channels without an authorizer are denied');
        self::assertSame(['id' => 1, 'team' => 'red'], $manager->authorize($ann, 'presence-team.red.chat'), 'presence channels return member info');

        $this->expectException(InvalidArgumentException::class);
        new Channel('bad name');
    }
}
