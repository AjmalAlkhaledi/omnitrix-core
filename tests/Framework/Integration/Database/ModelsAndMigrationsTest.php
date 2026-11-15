<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Integration\Database;

use DateTimeImmutable;
use Omnitrix\Application;
use Omnitrix\Database\Collection;
use Omnitrix\Database\EagerLoader;
use Omnitrix\Database\Migrations\Migrator;
use Omnitrix\Database\Model;
use Omnitrix\Database\ModelNotFoundException;
use Omnitrix\Database\ModelQuery;
use Omnitrix\Database\Paginator;
use Omnitrix\Database\Relations\BelongsTo;
use Omnitrix\Database\Relations\BelongsToMany;
use Omnitrix\Database\Relations\HasMany;
use Omnitrix\Database\Relations\HasOne;
use Omnitrix\Database\Relations\HasOneOrMany;
use Omnitrix\Database\Relations\Relation;
use Omnitrix\Database\Schema\Schema;
use Omnitrix\Facades\Async;
use Omnitrix\Facades\DB;
use Omnitrix\Tests\Support\Models\Comment;
use Omnitrix\Tests\Support\Models\Post;
use Omnitrix\Tests\Support\Models\Profile;
use Omnitrix\Tests\Support\Models\Tag;
use Omnitrix\Tests\Support\Models\User;
use Omnitrix\Tests\Support\Services;
use Omnitrix\Tests\Support\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;

use function array_column;
use function dirname;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Migrations, models and relationships against a real MariaDB. Uses a dedicated database so
 * dropping every table cannot affect other suites.
 */
#[CoversClass(Migrator::class)]
#[CoversClass(Schema::class)]
#[CoversClass(Model::class)]
#[CoversClass(ModelQuery::class)]
#[CoversClass(Collection::class)]
#[CoversClass(Paginator::class)]
#[CoversClass(EagerLoader::class)]
#[CoversClass(ModelNotFoundException::class)]
#[CoversClass(Relation::class)]
#[CoversClass(HasOneOrMany::class)]
#[CoversClass(HasMany::class)]
#[CoversClass(HasOne::class)]
#[CoversClass(BelongsTo::class)]
#[CoversClass(BelongsToMany::class)]
#[CoversClass(Application::class)]
final class ModelsAndMigrationsTest extends TestCase
{
    public static function database(): string
    {
        return Services::dbName() . '_orm';
    }

    /** @return array<string, mixed> */
    public static function config(): array
    {
        return ['database' => [
            'driver' => 'mariadb', 'host' => Services::dbHost(), 'port' => Services::dbPort(), 'database' => self::database(),
            'username' => Services::dbUser(), 'password' => Services::dbPassword(), 'pool' => ['min' => 0, 'max' => 8],
        ]];
    }

    public static function createDatabase(): void
    {
        $pdo = new PDO('mysql:host=' . Services::dbHost() . ';port=' . Services::dbPort(), Services::dbUser(), Services::dbPassword());
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . self::database() . '`');
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::createDatabase();
    }

    public function testMigrateStatusRollbackAndFresh(): void
    {
        $this->withDatabase(function (Migrator $migrator): void {
            $schema = new Schema(DB::connection());
            $migrator->fresh();
            self::assertSame(['comments', 'migrations', 'post_tag', 'posts', 'profiles', 'tags', 'users'], $schema->tables());
            self::assertSame([], $migrator->migrate(), 'nothing pending after fresh');
            self::assertSame([1, 1, 1], array_column($migrator->status(), 'batch'));

            self::assertSame(['2026_01_01_000003_create_tags_table', '2026_01_01_000002_create_posts_table', '2026_01_01_000001_create_users_table'], $migrator->rollback());
            self::assertSame(['migrations'], $schema->tables());
            self::assertSame([false, false, false], array_column($migrator->status(), 'ran'));

            self::assertCount(3, $migrator->migrate());
            self::assertTrue($schema->hasTable('post_tag'));
            self::assertTrue($schema->hasColumn('users', 'settings'));
            self::assertFalse($schema->hasColumn('users', 'nope'));
            self::assertCount(3, $migrator->reset());
            self::assertFalse($schema->hasTable('users'));
        });
    }

    public function testCreateFindUpdateDeleteWithCastsAndTimestamps(): void
    {
        $this->withDatabase(function (Migrator $migrator): void {
            $migrator->fresh();
            $user = User::create(['name' => 'Ann', 'email' => 'ann@example.com', 'password' => 'secret', 'is_admin' => true, 'settings' => ['theme' => 'dark'], 'id' => 999]);

            self::assertTrue($user->exists());
            self::assertIsInt($user->id);
            self::assertNotSame(999, $user->id, 'id is not fillable');
            self::assertInstanceOf(DateTimeImmutable::class, $user->created_at);

            $found = User::findOrFail($user->id);
            self::assertTrue($found->is_admin);
            self::assertSame(['theme' => 'dark'], $found->settings);
            self::assertFalse($found->isDirty());
            $json = json_encode($found, JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('secret', $json, 'hidden attributes stay out of JSON');
            self::assertStringContainsString('"is_admin":true', $json);

            $found->update(['name' => 'Ann B.', 'email' => 'ann.b@example.com']);
            self::assertSame('Ann B.', User::findOrFail($user->id)->name);
            self::assertSame(1, User::where('email', 'ann.b@example.com')->count());
            self::assertNull(User::find(123456));

            self::assertTrue($found->delete());
            try {
                User::findOrFail($user->id);
                self::fail('expected ModelNotFoundException');
            } catch (ModelNotFoundException $e) {
                self::assertSame(404, $e->status());
                self::assertSame('User not found', $e->getMessage());
            }
        });
    }

    public function testRelationshipsLazyAndEager(): void
    {
        $this->withDatabase(function (Migrator $migrator): void {
            $migrator->fresh();
            $ann = User::create(['name' => 'Ann', 'email' => 'ann@example.com', 'password' => 'x']);
            $bob = User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'x']);
            $ann->profile()->create(['bio' => 'Writer']);
            $first = $ann->posts()->create(['title' => 'First', 'published' => true]);
            $ann->posts()->create(['title' => 'Draft', 'published' => false]);
            $bob->posts()->create(['title' => 'Bob post', 'published' => true]);
            $first->comments()->create(['body' => 'Nice']);
            $php = Tag::create(['name' => 'php']);
            $async = Tag::create(['name' => 'async']);
            $first->tags()->attach([$php, $async->id]);

            // lazy
            self::assertSame(2, $ann->posts->count());
            self::assertSame('Writer', $ann->profile?->bio);
            self::assertSame('Ann', Post::where('title', 'First')->firstOrFail()->user?->name);
            self::assertSame(['async', 'php'], Post::findOrFail($first->id)->tags()->orderBy('name')->get()->pluck('name'));
            self::assertSame(['First'], Tag::findOrFail($php->id)->posts->pluck('title'));
            self::assertSame(1, $ann->posts()->where('published', true)->count());

            // eager, nested and constrained
            $users = User::with(['posts' => static fn(ModelQuery $q) => $q->where('published', true)], 'posts.tags', 'posts.comments', 'profile')->orderBy('id')->get();
            self::assertSame(['Ann', 'Bob'], $users->pluck('name'));
            [$a, $b] = $users->all();
            self::assertTrue($a->relationLoaded('posts'));
            self::assertSame(['First'], $a->posts->pluck('title'));
            $annPost = $a->posts->first();
            $bobPost = $b->posts->first();
            self::assertNotNull($annPost);
            self::assertNotNull($bobPost);
            self::assertEqualsCanonicalizing(['php', 'async'], $annPost->tags->pluck('name'), 'eager pivot rows have no guaranteed order');
            self::assertSame(['Nice'], $annPost->comments->pluck('body'));
            self::assertNull($b->profile);
            self::assertSame([], $bobPost->tags->pluck('name'));
            self::assertArrayNotHasKey('omnitrix_pivot_parent', $annPost->tags->first()?->toArray() ?? [], 'pivot helper column is removed');
            self::assertStringContainsString('"profile":{"id":1,"user_id":1,"bio":"Writer"}', json_encode($a, JSON_THROW_ON_ERROR));

            $comments = Comment::with('post.user')->get();
            self::assertSame('Ann', $comments->first()?->post?->user?->name);

            // pivot sync / detach
            $result = $first->tags()->sync([$async->id, Tag::create(['name' => 'mysql'])->id]);
            self::assertCount(1, $result['attached']);
            self::assertSame([$php->id], $result['detached']);
            self::assertSame(['async', 'mysql'], $first->tags()->orderBy('name')->get()->pluck('name'));
            self::assertSame(2, $first->tags()->detach());

            // belongsTo associate
            $profile = Profile::findOrFail(1);
            $profile->user()->associate($bob);
            $profile->save();
            self::assertSame('Bob', Profile::findOrFail(1)->user?->name);

            // cascade from the schema
            $ann->delete();
            self::assertSame(1, Post::query()->count());
        });
    }

    public function testPaginationAndConcurrentQueries(): void
    {
        $this->withDatabase(function (Migrator $migrator): void {
            $migrator->fresh();
            $user = User::create(['name' => 'Ann', 'email' => 'ann@example.com', 'password' => 'x']);
            for ($i = 1; $i <= 7; $i++) {
                $user->posts()->create(['title' => "Post {$i}", 'published' => $i % 2 === 1]);
            }

            $page = Post::query()->orderBy('id')->paginate(perPage: 3, page: 3);
            self::assertSame(['data' => [['title' => 'Post 7']], 'meta' => ['total' => 7, 'per_page' => 3, 'current_page' => 3, 'last_page' => 3]], [
                'data' => [['title' => $page->items->first()?->title]],
                'meta' => $page->toArray()['meta'],
            ]);
            self::assertFalse($page->hasMorePages());

            $results = Async::all([
                'published' => static fn(): int => Post::where('published', true)->count(),
                'drafts' => static fn(): int => Post::where('published', false)->count(),
                'user' => static fn(): ?string => User::with('posts')->first()?->name,
                'sleep' => static fn(): mixed => DB::selectOne('select sleep(0.2) as s'),
            ]);
            self::assertSame(4, $results['published']);
            self::assertSame(3, $results['drafts']);
            self::assertSame('Ann', $results['user']);
        });
    }

    /** @param callable(Migrator): void $body */
    private function withDatabase(callable $body): void
    {
        $app = Application::create(['server' => ['port' => 18600]] + self::config());
        $app->withServices(static function () use ($body): void {
            $body(new Migrator(DB::manager(), dirname(__DIR__, 2) . '/Fixtures/migrations'));
        });
    }
}
