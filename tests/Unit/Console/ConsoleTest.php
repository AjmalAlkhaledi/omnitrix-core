<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Unit\Console;

use Omnitrix\Console\Command;
use Omnitrix\Console\Commands\AboutCommand;
use Omnitrix\Console\Commands\MakeCommand;
use Omnitrix\Console\Commands\MakeControllerCommand;
use Omnitrix\Console\Commands\MakeJobCommand;
use Omnitrix\Console\Commands\MakeMiddlewareCommand;
use Omnitrix\Console\Commands\MakeMigrationCommand;
use Omnitrix\Console\Commands\MakeModelCommand;
use Omnitrix\Console\Commands\MakeSeederCommand;
use Omnitrix\Console\Commands\RoutesCommand;
use Omnitrix\Console\Console;
use Omnitrix\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Tester\ApplicationTester;

use function dirname;
use function exec;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

use const PHP_BINARY;

/** The project console: generators, route listing and project discovery. */
#[CoversClass(Console::class)]
#[CoversClass(Command::class)]
#[CoversClass(MakeCommand::class)]
#[CoversClass(MakeControllerCommand::class)]
#[CoversClass(MakeModelCommand::class)]
#[CoversClass(MakeMigrationCommand::class)]
#[CoversClass(MakeMiddlewareCommand::class)]
#[CoversClass(MakeJobCommand::class)]
#[CoversClass(MakeSeederCommand::class)]
#[CoversClass(RoutesCommand::class)]
#[CoversClass(AboutCommand::class)]
final class ConsoleTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = sys_get_temp_dir() . '/omnitrix-console-' . uniqid();
        mkdir($this->project);
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        file_put_contents($this->project . '/bootstrap.php', <<<PHP
            <?php
            require '{$autoload}';
            \$app = Omnitrix\\Application::create(['app' => ['env' => 'local'], 'server' => ['port' => 18555, 'workers' => 1]]);
            \$app->get('/users/{id:\\d+}', fn () => [])->name('users.show');
            \$app->post('/users', 'App\\\\Controllers\\\\UserController::store');
            return \$app;
            PHP);
    }

    public function testGeneratorsCreateValidPhpFiles(): void
    {
        $tester = $this->tester();

        self::assertSame(0, $tester->run(['command' => 'make:controller', 'name' => 'Admin/UserController', '--model' => 'User']));
        self::assertSame(0, $tester->run(['command' => 'make:model', 'name' => 'BlogPost', '--migration' => true]));
        self::assertSame(0, $tester->run(['command' => 'make:middleware', 'name' => 'RequireApiKey']));
        self::assertSame(0, $tester->run(['command' => 'make:job', 'name' => 'SendWelcomeEmail']));
        self::assertStringContainsString("'send.welcome.email' => \\App\\Jobs\\SendWelcomeEmail::class", $tester->getDisplay());
        self::assertSame(0, $tester->run(['command' => 'make:seeder', 'name' => 'UserSeeder']));
        self::assertSame(0, $tester->run(['command' => 'make:migration', 'name' => 'add_status_to_orders', '--table' => 'orders']));

        $controller = (string) file_get_contents($this->project . '/src/Controllers/Admin/UserController.php');
        self::assertStringContainsString('namespace App\\Controllers\\Admin;', $controller);
        self::assertStringContainsString('return User::findOrFail($request->routeInt(\'id\'));', $controller);
        self::assertStringContainsString("protected static ?string \$table = 'blog_posts';", (string) file_get_contents($this->project . '/src/Models/BlogPost.php'));
        self::assertStringContainsString('implements MiddlewareInterface', (string) file_get_contents($this->project . '/src/Middleware/RequireApiKey.php'));
        self::assertStringContainsString('namespace Database\\Seeds;', (string) file_get_contents($this->project . '/database/seeds/UserSeeder.php'));
        $create = glob($this->project . '/database/migrations/*_create_blog_posts_table.php');
        $alter = glob($this->project . '/database/migrations/*_add_status_to_orders.php');
        self::assertCount(1, $create === false ? [] : $create);
        self::assertCount(1, $alter === false ? [] : $alter);
        self::assertStringContainsString("\$schema->create('blog_posts'", (string) file_get_contents(($create === false ? [] : $create)[0] ?? ''));
        self::assertStringContainsString("\$schema->table('orders'", (string) file_get_contents(($alter === false ? [] : $alter)[0] ?? ''));

        $files = [];
        foreach (['/src/*/*.php', '/src/*/*/*.php', '/database/*/*.php'] as $pattern) {
            $found = glob($this->project . $pattern);
            $files = [...$files, ...($found === false ? [] : $found)];
        }
        self::assertCount(7, $files);
        foreach ($files as $file) {
            // The exit status of exec() is not reliable under the runtime hooks; check the message.
            $out = [];
            exec(PHP_BINARY . ' -l ' . escapeshellarg($file) . ' 2>&1', $out);
            self::assertStringContainsString('No syntax errors detected', implode("\n", $out), $file);
        }
    }

    public function testExistingFilesAreKeptUnlessForced(): void
    {
        $tester = $this->tester();
        self::assertSame(0, $tester->run(['command' => 'make:job', 'name' => 'Ping']));
        self::assertSame(1, $tester->run(['command' => 'make:job', 'name' => 'Ping']));
        self::assertStringContainsString('already exists', $tester->getDisplay());
        self::assertSame(0, $tester->run(['command' => 'make:job', 'name' => 'Ping', '--force' => true]));
        self::assertSame(1, $tester->run(['command' => 'make:migration', 'name' => 'bad-name!']));
    }

    public function testRoutesAndAboutDescribeTheProject(): void
    {
        $tester = $this->tester();

        self::assertSame(0, $tester->run(['command' => 'routes']));
        $display = $tester->getDisplay();
        self::assertStringContainsString('/users/{id:\d+}', $display);
        self::assertStringContainsString('users.show', $display);
        self::assertStringContainsString('App\Controllers\UserController::store', $display);

        self::assertSame(0, $tester->run(['command' => 'about']));
        self::assertStringContainsString('127.0.0.1:18555', $tester->getDisplay());
        self::assertStringContainsString('local', $tester->getDisplay());
    }

    public function testMissingBootstrapIsReported(): void
    {
        $application = (new Console(sys_get_temp_dir() . '/omnitrix-none-' . uniqid()))->application();
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);

        self::assertSame(1, $tester->run(['command' => 'routes']));
        self::assertStringContainsString('No bootstrap.php found', $tester->getDisplay());
    }

    private function tester(): ApplicationTester
    {
        $application = (new Console($this->project))->application();
        $application->setAutoExit(false);
        $application->setCatchExceptions(true);

        return new ApplicationTester($application);
    }
}
