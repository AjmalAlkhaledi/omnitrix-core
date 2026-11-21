<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Integration\Events;

use Omnitrix\Application;
use Omnitrix\Database\Migrations\Migrator;
use Omnitrix\Events\Dispatcher;
use Omnitrix\Events\ShouldDispatchAfterCommit;
use Omnitrix\Facades\DB;
use Omnitrix\Facades\Event;
use Omnitrix\Tests\Integration\Database\ModelsAndMigrationsTest;
use Omnitrix\Tests\Support\Models\Post;
use Omnitrix\Tests\Support\Models\User;
use Omnitrix\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

use function dirname;
use function strtoupper;

final class UserRegistered implements ShouldDispatchAfterCommit
{
    public function __construct(public string $email) {}
}

final class UserObserver
{
    /** @var list<string> */
    public static array $log = [];

    public function created(User $user): void
    {
        self::$log[] = 'created ' . $user->email;
    }

    public function deleting(User $user): bool
    {
        self::$log[] = 'deleting ' . $user->email;

        return $user->email !== 'keep@example.com';
    }
}

/** Events with the application: configuration listeners, model events, after-commit events. */
#[CoversClass(Dispatcher::class)]
final class EventsTest extends TestCase
{
    /** @var list<string> */
    public static array $seen = [];

    protected function setUp(): void
    {
        parent::setUp();
        ModelsAndMigrationsTest::createDatabase();
        self::$seen = [];
        UserObserver::$log = [];
        User::flushEventListeners();
    }

    protected function tearDown(): void
    {
        User::flushEventListeners();
        parent::tearDown();
    }

    public static function record(string $value): void
    {
        self::$seen[] = $value;
    }

    public function testModelEventsCancelChangeAndObserve(): void
    {
        User::creating(static fn(User $u): bool => $u->email !== 'blocked@example.com');
        User::updating(static function (User $u): void {
            $u->name = strtoupper((string) $u->name);
        });
        User::observe(UserObserver::class);
        $config = ModelsAndMigrationsTest::config() + ['events' => ['listen' => ['model.created: ' . User::class => [static fn(User $u) => self::record('config ' . $u->email)]]]];
        $app = Application::create(['server' => ['port' => 18650]] + $config);
        $app->listen('model.deleted: *', static fn(object $model) => self::record('deleted ' . $model::class));

        $app->withServices(static function (): void {
            (new Migrator(DB::manager(), dirname(__DIR__, 2) . '/Fixtures/migrations'))->fresh();
            $blocked = new User();
            $blocked->fill(['name' => 'B', 'email' => 'blocked@example.com', 'password' => 'x']);
            self::assertFalse($blocked->save(), 'a creating listener returning false cancels');
            self::assertSame(0, User::query()->count());

            $ann = User::create(['name' => 'Ann', 'email' => 'ann@example.com', 'password' => 'x']);
            $ann->update(['name' => 'ann b.']);
            self::assertSame('ANN B.', User::findOrFail($ann->id)->name, 'updating listeners can change attributes before the write');

            $keep = User::create(['name' => 'K', 'email' => 'keep@example.com', 'password' => 'x']);
            self::assertFalse($keep->delete(), 'the observer cancels deleting');
            self::assertNotNull(User::find($keep->id));
            self::assertTrue($ann->delete());

            self::assertSame(['created ann@example.com', 'created keep@example.com', 'deleting keep@example.com', 'deleting ann@example.com'], UserObserver::$log);
            self::assertSame(['config ann@example.com', 'config keep@example.com', 'deleted ' . User::class], self::$seen, 'model events reach the dispatcher');
            self::assertSame(0, Post::query()->count());
        });
    }

    public function testAfterCommitEventsWaitForTheTransactionAndFakesRecord(): void
    {
        $app = Application::create(['server' => ['port' => 18651]] + ModelsAndMigrationsTest::config());
        $app->listen(UserRegistered::class, static fn(UserRegistered $e) => self::record($e->email));
        $app->withServices(static function (): void {
            DB::transaction(static function (): void {
                Event::dispatch(new UserRegistered('a@example.com'));
                self::assertSame([], self::$seen, 'not before the commit');
            });
            self::assertSame(['a@example.com'], self::$seen);
            try {
                DB::transaction(static function (): void {
                    Event::dispatch(new UserRegistered('b@example.com'));
                    throw new RuntimeException('rollback');
                });
            } catch (RuntimeException) {
                // rolled back
            }
            self::assertSame(['a@example.com'], self::$seen, 'never after a rollback');

            $fake = Event::fake();
            Event::dispatch(new UserRegistered('c@example.com'));
            $fake->assertDispatched(UserRegistered::class, static fn(UserRegistered $e): bool => $e->email === 'c@example.com');
            self::assertSame(['a@example.com'], self::$seen, 'faked events run no listener');
        });
    }
}
