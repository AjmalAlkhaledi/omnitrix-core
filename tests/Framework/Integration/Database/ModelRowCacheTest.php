<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Integration\Database;

use Omnitrix\Application;
use Omnitrix\Core\Runtime\Config\InvalidConfigurationException;
use Omnitrix\Database\Migrations\Migrator;
use Omnitrix\Facades\DB;
use Omnitrix\Tests\Support\Models\Post;
use Omnitrix\Tests\Support\Models\User;
use Omnitrix\Tests\Support\Services;
use Omnitrix\Tests\Support\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\CoversNothing;

use function dirname;
use function is_array;
use function uniqid;

/**
 * Models get the primary-key row cache from configuration alone: find(), findMany() and
 * belongsTo relations are served from it, and save()/delete() keep it consistent.
 */
#[CoversNothing]
final class ModelRowCacheTest extends TestCase
{
    public function testTheCacheWithoutRedisIsRejectedWhenTheApplicationIsCreated(): void
    {
        $config = ModelsAndMigrationsTest::config();
        self::assertTrue(is_array($config['database']));
        $config['database']['cache'] = ['tables' => ['users']];

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('database.default.cache');
        Application::create(['server' => ['port' => 18621]] + $config);
    }

    public function testModelsUseTheRowCacheWithoutApplicationCode(): void
    {
        ModelsAndMigrationsTest::createDatabase();
        $config = ModelsAndMigrationsTest::config();
        self::assertTrue(is_array($config['database']));
        $config['database']['cache'] = ['tables' => ['users', 'posts'], 'queries' => ['posts' => ['user_id']], 'ttl' => 60];
        $config['redis'] = ['host' => Services::redisHost(), 'port' => Services::redisPort(), 'prefix' => 'model-cache-' . uniqid() . ':'];
        $app = Application::create(['server' => ['port' => 18620]] + $config);
        $behind = new PDO('mysql:host=' . Services::dbHost() . ';port=' . Services::dbPort() . ';dbname=' . ModelsAndMigrationsTest::database(), Services::dbUser(), Services::dbPassword());

        $app->withServices(static function () use ($behind): void {
            (new Migrator(DB::manager(), dirname(__DIR__, 2) . '/Fixtures/migrations'))->fresh();
            self::assertTrue(DB::connection()->cacheEnabled());
            $ann = User::create(['name' => 'Ann', 'email' => 'ann@example.com', 'password' => 'x']);
            $bob = User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'x']);
            $post = $ann->posts()->create(['title' => 'Hello']);

            self::assertSame('Ann', User::findOrFail($ann->id)->name);
            $behind->exec("UPDATE users SET name = 'changed behind' WHERE id IN ({$ann->id}, {$bob->id})");
            self::assertSame('Ann', User::findOrFail($ann->id)->name, 'find() is served from the cache');

            $ann->update(['name' => 'Ann B.']);
            self::assertSame('Ann B.', User::findOrFail($ann->id)->name, 'save() invalidates the row');

            self::assertSame('Ann B.', Post::with('user')->get()->first()?->user?->name, 'eager belongsTo uses the cache');
            self::assertSame(['Ann B.', 'changed behind'], User::query()->findMany([$ann->id, $bob->id])->pluck('name'), 'uncached rows are read once');
            $behind->exec("UPDATE users SET name = 'twice' WHERE id = {$bob->id}");
            self::assertSame('changed behind', User::findOrFail($bob->id)->name, 'and then cached');

            $titles = static fn(int $user): array => Post::where('user_id', $user)->orderBy('id')->cache(60)->get()->pluck('title');
            self::assertSame(['Hello'], $titles($ann->id));
            $behind->exec("UPDATE posts SET title = 'behind' WHERE id = {$post->id}");
            self::assertSame(['Hello'], $titles($ann->id), 'model queries use the query cache');
            $bob->posts()->create(['title' => 'Bob post']);
            self::assertSame(['Hello'], $titles($ann->id), "a new post by Bob leaves Ann's query cached");
            $ann->posts()->create(['title' => 'Second']);
            self::assertSame(['behind', 'Second'], $titles($ann->id));
            $page = Post::where('user_id', $ann->id)->orderBy('id')->cache(60)->paginate(perPage: 1, page: 2);
            self::assertSame(2, $page->total);
            self::assertSame('Second', $page->items->first()?->title);

            $ann->delete();
            self::assertNull(Post::find($post->id), 'ON DELETE CASCADE into a cached table is invalidated too');
            self::assertNull(User::find($ann->id));
            self::assertGreaterThan(0, DB::connection()->cacheStats()['hits']);
        });
    }
}
