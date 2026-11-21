<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Integration\Database;

use Omnitrix\Application;
use Omnitrix\Database\Collection;
use Omnitrix\Database\Factories\Factory;
use Omnitrix\Database\Factories\HasFactory;
use Omnitrix\Database\Migrations\Migrator;
use Omnitrix\Database\Model;
use Omnitrix\Facades\DB;
use Omnitrix\Tests\Support\Models\Post;
use Omnitrix\Tests\Support\Models\User;
use Omnitrix\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

use function dirname;
use function is_string;
use function strtoupper;

final class TestUserFactory extends Factory
{
    protected string $model = User::class;

    public function definition(): array
    {
        return ['name' => $this->faker->name(), 'email' => $this->faker->unique()->email(), 'password' => 'secret', 'is_admin' => false];
    }

    public function admin(): static
    {
        return $this->state(['is_admin' => true]);
    }
}

final class FactoryUser extends Model
{
    use HasFactory;

    protected static ?string $table = 'users';

    protected static string $factory = TestUserFactory::class;
}

final class TestPostFactory extends Factory
{
    protected string $model = Post::class;

    public function definition(): array
    {
        return ['title' => $this->faker->sentence(4), 'published' => false, 'user_id' => TestUserFactory::new()];
    }
}

#[CoversClass(Factory::class)]
final class FactoriesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ModelsAndMigrationsTest::createDatabase();
    }

    public function testMakeCreateStatesSequencesAndRelations(): void
    {
        $app = Application::create(['server' => ['port' => 18690]] + ModelsAndMigrationsTest::config());
        $app->withServices(static function (): void {
            (new Migrator(DB::manager(), dirname(__DIR__, 2) . '/Fixtures/migrations'))->fresh();
            Factory::seed(3);

            $made = TestUserFactory::new()->make();
            self::assertInstanceOf(User::class, $made);
            self::assertFalse($made->exists());
            self::assertSame(0, User::query()->count(), 'make() does not save');

            $admins = TestUserFactory::new()->count(3)->admin()->create();
            self::assertInstanceOf(Collection::class, $admins);
            self::assertCount(3, $admins);
            self::assertSame([true, true, true], $admins->map(static fn(Model $u): bool => $u instanceof User && $u->is_admin === true));
            self::assertSame(3, User::query()->count());

            $teams = TestUserFactory::new()->count(4)->sequence(['name' => 'Red'], ['name' => 'Blue'])->make();
            self::assertInstanceOf(Collection::class, $teams);
            self::assertSame(['Red', 'Blue', 'Red', 'Blue'], $teams->pluck('name'));
            $upper = TestUserFactory::new()->state(static fn(array $a): array => ['name' => is_string($a['name'] ?? null) ? strtoupper($a['name']) : ''])->make(['name' => 'override']);
            self::assertInstanceOf(User::class, $upper);
            self::assertSame('override', $upper->name, 'explicit attributes win');

            $author = TestUserFactory::new()->has(TestPostFactory::new()->count(2), 'user_id')->create();
            self::assertInstanceOf(User::class, $author);
            self::assertSame(2, Post::where('user_id', $author->id)->count());

            $post = TestPostFactory::new()->create();
            self::assertInstanceOf(Post::class, $post);
            self::assertNotNull(User::find($post->user_id), 'a factory as attribute creates the parent');
            $owned = TestPostFactory::new()->for($author, 'user_id')->create();
            self::assertInstanceOf(Post::class, $owned);
            self::assertSame($author->id, $owned->user_id);

            $raw = TestUserFactory::new()->count(2)->raw();
            self::assertCount(2, $raw);
            self::assertSame(2, \Omnitrix\Support\Collection::make($raw)->pluck('email')->unique()->count(), 'unique emails');
            self::assertInstanceOf(User::class, FactoryUser::factory()->make(), 'HasFactory resolves the factory');

            $seen = [];
            TestUserFactory::new()->afterCreating(static function (Model $u) use (&$seen): void {
                $seen[] = $u->getKey();
            })->count(2)->create();
            self::assertCount(2, $seen);
        });
    }
}
