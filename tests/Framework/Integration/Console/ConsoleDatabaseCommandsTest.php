<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Integration\Console;

use Omnitrix\Console\Commands\MigrateCommand;
use Omnitrix\Console\Commands\MigrateFreshCommand;
use Omnitrix\Console\Commands\MigrateResetCommand;
use Omnitrix\Console\Commands\MigrateRollbackCommand;
use Omnitrix\Console\Commands\MigrateStatusCommand;
use Omnitrix\Console\Commands\MigrationCommand;
use Omnitrix\Console\Commands\QueueWorkCommand;
use Omnitrix\Console\Commands\SeedCommand;
use Omnitrix\Console\Console;
use Omnitrix\Core\Queue\QueueProducer;
use Omnitrix\Core\Queue\RedisQueue;
use Omnitrix\Core\Redis\RedisClient;
use Omnitrix\Core\Redis\RedisConfig;
use Omnitrix\Facades\Async;
use Omnitrix\Tests\Integration\Database\ModelsAndMigrationsTest;
use Omnitrix\Tests\Support\ProcessRunner;
use Omnitrix\Tests\Support\Services;
use Omnitrix\Tests\Support\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Tester\ApplicationTester;

use function basename;
use function copy;
use function dirname;
use function file_put_contents;
use function glob;
use function is_numeric;
use function microtime;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;
use function var_export;

use const SIGTERM;

/** `php omnitrix migrate*`, `db:seed` and `queue:work` against real MariaDB and Redis. */
#[CoversClass(MigrationCommand::class)]
#[CoversClass(MigrateCommand::class)]
#[CoversClass(MigrateStatusCommand::class)]
#[CoversClass(MigrateRollbackCommand::class)]
#[CoversClass(MigrateResetCommand::class)]
#[CoversClass(MigrateFreshCommand::class)]
#[CoversClass(SeedCommand::class)]
#[CoversClass(QueueWorkCommand::class)]
final class ConsoleDatabaseCommandsTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        parent::setUp();
        ModelsAndMigrationsTest::createDatabase();
    }

    public function testMigrationAndSeedCommands(): void
    {
        $tester = $this->project('local');

        self::assertSame(0, $tester->run(['command' => 'migrate:fresh']), $tester->getDisplay());
        self::assertStringContainsString('Migrated 2026_01_01_000003_create_tags_table', $tester->getDisplay());
        self::assertSame(0, $tester->run(['command' => 'migrate']));
        self::assertStringContainsString('Nothing to migrate.', $tester->getDisplay());

        self::assertSame(0, $tester->run(['command' => 'db:seed', '--class' => 'Omnitrix\\Tests\\Support\\Models\\DemoSeeder']), $tester->getDisplay());
        self::assertStringContainsString('Seeding Omnitrix\\Tests\\Support\\Models\\DemoUserSeeder', $tester->getDisplay());
        self::assertSame(1, $this->rows('users'));

        self::assertSame(0, $tester->run(['command' => 'migrate:status']));
        self::assertMatchesRegularExpression('/2026_01_01_000002_create_posts_table\s+\|\s+yes\s+\|\s+1/', $tester->getDisplay());

        self::assertSame(0, $tester->run(['command' => 'migrate:rollback', '--step' => '1']));
        self::assertStringContainsString('Reverted 2026_01_01_000001_create_users_table', $tester->getDisplay());
        self::assertSame(0, $tester->run(['command' => 'migrate:status']));
        self::assertStringContainsString('pending', $tester->getDisplay());
        self::assertSame(2, $tester->run(['command' => 'migrate:rollback', '--step' => 'x']));
        self::assertSame(0, $tester->run(['command' => 'migrate:reset']));
        self::assertStringContainsString('Nothing to reset.', $tester->getDisplay());
    }

    public function testDestructiveCommandsNeedForceInProduction(): void
    {
        $tester = $this->project('production');

        self::assertSame(1, $tester->run(['command' => 'migrate:fresh']));
        self::assertStringContainsString('--force', $tester->getDisplay());
        self::assertSame(1, $tester->run(['command' => 'db:seed']));
        self::assertSame(0, $tester->run(['command' => 'migrate:fresh', '--force' => true]), $tester->getDisplay());
        self::assertSame(0, $tester->run(['command' => 'migrate']), 'migrate itself is not destructive');
    }

    public function testQueueWorkRunsConfiguredJobClassesWithFacades(): void
    {
        $tester = $this->project('local');
        self::assertSame(0, $tester->run(['command' => 'migrate:fresh']), $tester->getDisplay());
        $queue = 'console' . uniqid();
        $redis = new RedisClient(RedisConfig::fromArray(['host' => Services::redisHost(), 'port' => Services::redisPort()]));
        $producer = new QueueProducer(new RedisQueue($redis), $queue);
        foreach (['ann', 'bob'] as $name) {
            $producer->dispatch('users.create', ['name' => $name]);
        }

        $worker = new ProcessRunner($this->project . '/omnitrix', ['queue:work', '--queue', $queue, '--concurrency', '2']);
        try {
            $deadline = microtime(true) + 15.0;
            while ($this->rows('users') < 2 && microtime(true) < $deadline) {
                Async::sleep(0.1);
            }
            self::assertSame(2, $this->rows('users'), $worker->stdout() . $worker->stderr());
            $worker->signal(SIGTERM);
            self::assertSame(0, $worker->wait(10.0), $worker->stderr());
            self::assertStringContainsString('processed=2', $worker->stdout());
        } finally {
            $worker->stop(5.0);
            $redis->close();
        }
    }

    private function project(string $env): ApplicationTester
    {
        $this->project = sys_get_temp_dir() . '/omnitrix-project-' . uniqid();
        mkdir($this->project . '/database/migrations', 0o777, true);
        $migrations = glob(dirname(__DIR__, 2) . '/Fixtures/migrations/*.php');
        foreach ($migrations === false ? [] : $migrations as $file) {
            copy($file, $this->project . '/database/migrations/' . basename($file));
        }
        $config = ModelsAndMigrationsTest::config() + [
            'app' => ['env' => $env],
            'server' => ['port' => 18610],
            'redis' => ['host' => Services::redisHost(), 'port' => Services::redisPort()],
            'queue' => ['jobs' => ['users.create' => 'Omnitrix\\Tests\\Support\\Jobs\\CreateUserJob']],
        ];
        $autoload = dirname(__DIR__, 4) . '/vendor/autoload.php';
        file_put_contents($this->project . '/bootstrap.php', "<?php\nrequire " . var_export($autoload, true) . ";\nreturn Omnitrix\\Application::create(" . var_export($config, true) . ");\n");
        file_put_contents($this->project . '/omnitrix', "<?php\nrequire " . var_export($autoload, true) . ";\nexit((new Omnitrix\\Console\\Console(__DIR__))->run());\n");
        $application = (new Console($this->project))->application();
        $application->setAutoExit(false);

        return new ApplicationTester($application);
    }

    private function rows(string $table): int
    {
        $pdo = new PDO('mysql:host=' . Services::dbHost() . ';port=' . Services::dbPort() . ';dbname=' . ModelsAndMigrationsTest::database(), Services::dbUser(), Services::dbPassword());
        $statement = $pdo->query("select count(*) from `{$table}`");
        $value = $statement === false ? 0 : $statement->fetchColumn();

        return is_numeric($value) ? (int) $value : 0;
    }
}
