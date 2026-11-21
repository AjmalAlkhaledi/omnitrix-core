<?php

declare(strict_types=1);

namespace Omnitrix\Console;

use Composer\InstalledVersions;
use LogicException;
use Omnitrix\Application;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function class_exists;
use function implode;
use function is_file;
use function rtrim;

/**
 * The project command line (`php omnitrix`). A project is a directory with a `bootstrap.php`
 * that returns the {@see Application}, plus the conventional folders:
 *
 * ```
 * bootstrap.php        builds and returns the Application
 * config/              one file per configuration section
 * routes/              route files loaded by bootstrap.php
 * src/                 application code (namespace App\)
 * database/migrations  migration files
 * database/seeds       seeders (namespace Database\Seeds\)
 * ```
 */
final class Console
{
    public const string NAME = 'Omnitrix';

    private ?Application $app = null;

    /** @var list<Command> */
    private array $extra = [];

    public function __construct(private readonly string $basePath) {}

    /** Runs the command line and returns the exit code. */
    public function run(?InputInterface $input = null, ?OutputInterface $output = null): int
    {
        $console = $this->application();
        $console->setAutoExit(false);

        return $console->run($input, $output);
    }

    /** Adds a project command (a subclass of {@see Command}). */
    public function add(Command $command): self
    {
        $this->extra[] = $command;

        return $this;
    }

    public function application(): ConsoleApplication
    {
        $console = new ConsoleApplication(self::NAME, self::version());
        foreach ([
            new Commands\AboutCommand($this),
            new Commands\KeyGenerateCommand($this),
            new Commands\ServeCommand($this),
            new Commands\RoutesCommand($this),
            new Commands\MigrateCommand($this),
            new Commands\MigrateRollbackCommand($this),
            new Commands\MigrateResetCommand($this),
            new Commands\MigrateFreshCommand($this),
            new Commands\MigrateStatusCommand($this),
            new Commands\SeedCommand($this),
            new Commands\QueueWorkCommand($this),
            new Commands\MakeControllerCommand($this),
            new Commands\MakeModelCommand($this),
            new Commands\MakeMigrationCommand($this),
            new Commands\MakeMiddlewareCommand($this),
            new Commands\MakeJobCommand($this),
            new Commands\MakeEventCommand($this),
            new Commands\MakeListenerCommand($this),
            new Commands\MakeSeederCommand($this),
            ...$this->extra,
        ] as $command) {
            $command->setConsole($this);
            $console->addCommand($command);
        }

        return $console;
    }

    /**
     * The project's application, built by `bootstrap.php` on first use.
     */
    public function app(): Application
    {
        if ($this->app === null) {
            $file = $this->path('bootstrap.php');
            if (!is_file($file)) {
                throw new LogicException("No bootstrap.php found in {$this->basePath}; run this command from the project root");
            }
            $app = (static fn(string $path): mixed => require $path)($file);
            if (!$app instanceof Application) {
                throw new LogicException('bootstrap.php must return an instance of ' . Application::class);
            }
            $this->app = $app;
        }

        return $this->app;
    }

    public function path(string ...$segments): string
    {
        return rtrim($this->basePath, '/') . ($segments === [] ? '' : '/' . implode('/', $segments));
    }

    public static function version(): string
    {
        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('omnitrix/core')) {
            return InstalledVersions::getPrettyVersion('omnitrix/core') ?? 'dev';
        }

        return 'dev';
    }
}
