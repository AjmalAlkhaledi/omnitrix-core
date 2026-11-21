<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Integration\Database;

use Omnitrix\Application;
use Omnitrix\Database\Migrations\Migrator;
use Omnitrix\Database\Schema\PostgresSchemaGrammar;
use Omnitrix\Database\Schema\Schema;
use Omnitrix\Facades\DB;
use Omnitrix\Tests\Support\Models\Post;
use Omnitrix\Tests\Support\Models\Tag;
use Omnitrix\Tests\Support\Models\User;
use Omnitrix\Tests\Support\Services;
use Omnitrix\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

use function array_column;
use function dirname;
use function file_put_contents;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Migrations, the schema builder and models on PostgreSQL (skipped when none is running,
 * required in CI with OMNITRIX_TEST_PG=1).
 */
#[CoversClass(PostgresSchemaGrammar::class)]
#[CoversClass(Schema::class)]
#[CoversClass(Migrator::class)]
final class PostgresModelsTest extends TestCase
{
    private const string DB = 'omnitrix_pg_orm';

    protected function setUp(): void
    {
        parent::setUp();
        Services::pgDatabase(self::DB);
    }

    public function testMigrationsSchemaAndModels(): void
    {
        $this->withApplication(function (Migrator $migrator): void {
            $schema = new Schema(DB::connection());
            $migrator->fresh();
            self::assertSame(['comments', 'migrations', 'post_tag', 'posts', 'profiles', 'tags', 'users'], $schema->tables());
            self::assertTrue($schema->hasColumn('users', 'settings'));
            self::assertFalse($schema->hasColumn('users', 'nope'));

            $ann = User::create(['name' => 'Ann', 'email' => 'ann@example.com', 'password' => 'x', 'is_admin' => true, 'settings' => ['theme' => 'dark']]);
            $found = User::findOrFail($ann->id);
            self::assertTrue($found->is_admin, 'boolean column');
            self::assertSame(['theme' => 'dark'], $found->settings, 'jsonb column with the array cast');
            $found->update(['name' => 'Ann B.']);
            self::assertSame('Ann B.', User::findOrFail($ann->id)->name);

            $first = $ann->posts()->create(['title' => 'First', 'published' => true]);
            $ann->posts()->create(['title' => 'Second']);
            $php = Tag::create(['name' => 'php']);
            $first->tags()->attach([$php->id]);
            self::assertSame(['php'], Post::findOrFail($first->id)->tags->pluck('name'));
            $users = User::with('posts')->orderBy('id')->get();
            self::assertSame(['First', 'Second'], $users->first()?->posts->pluck('title'));
            self::assertSame(1, Post::where('published', true)->count());
            $page = Post::query()->orderBy('id')->paginate(perPage: 1, page: 2);
            self::assertSame(2, $page->total);
            self::assertSame('Second', $page->items->first()?->title);

            $ann->delete();
            self::assertSame(0, Post::query()->count(), 'ON DELETE CASCADE');

            self::assertCount(3, $migrator->rollback());
            self::assertSame(['migrations'], $schema->tables());
        });
    }

    public function testAFailingMigrationLeavesNothingBehind(): void
    {
        $dir = sys_get_temp_dir() . '/omnitrix-pg-migrations-' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/2026_01_01_000001_half_done.php', <<<'PHP'
            <?php
            use Omnitrix\Database\Migrations\Migration;
            use Omnitrix\Database\Schema\Blueprint;
            use Omnitrix\Database\Schema\Schema;

            return new class extends Migration {
                public function up(Schema $schema): void
                {
                    $schema->create('half_done', static function (Blueprint $table): void {
                        $table->id();
                    });
                    throw new RuntimeException('second step fails');
                }

                public function down(Schema $schema): void
                {
                    $schema->dropIfExists('half_done');
                }
            };
            PHP);
        $this->withApplication(function (Migrator $migrator): void {
            try {
                $migrator->migrate();
                self::fail('the migration throws');
            } catch (RuntimeException $e) {
                self::assertSame('second step fails', $e->getMessage());
            }
            $schema = new Schema(DB::connection());
            self::assertFalse($schema->hasTable('half_done'), 'PostgreSQL rolled back the DDL of the failed migration');
            self::assertSame([], array_column(DB::connection()->table('migrations')->get(), 'migration'), 'and did not record it');
        }, $dir);
    }

    private function withApplication(callable $body, ?string $migrations = null): void
    {
        $app = Application::create(['server' => ['port' => 18640], 'database' => Services::pgConfig(self::DB)]);
        $path = $migrations ?? dirname(__DIR__, 2) . '/Fixtures/migrations';
        $app->withServices(static function () use ($body, $path): void {
            $body(new Migrator(DB::manager(), $path));
        });
    }
}
