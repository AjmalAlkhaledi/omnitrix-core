<?php

declare(strict_types=1);

namespace Omnitrix\Console\Commands;

use LogicException;
use Omnitrix\Console\Command;
use Omnitrix\Database\DatabaseCreator;
use Omnitrix\Database\Migrations\Migrator;
use Omnitrix\Database\Seeding\Seeder;
use Omnitrix\Facades\DB;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Throwable;

use function class_exists;
use function is_array;
use function is_subclass_of;
use function sprintf;

/** Shared plumbing for the migration and seeding commands. */
abstract class MigrationCommand extends Command
{
    public const string DEFAULT_SEEDER = 'Database\\Seeds\\DatabaseSeeder';

    /**
     * Runs `$body` with a migrator inside the application's services.
     *
     * @template T
     *
     * @param callable(Migrator): T $body
     *
     * @return T
     */
    protected function withMigrator(OutputInterface $output, callable $body): mixed
    {
        $database = $this->app()->config()['database'] ?? null;
        if (!is_array($database) || $database === []) {
            throw new LogicException("No 'database' configuration found (config/database.php)");
        }

        return $this->app()->withServices(function () use ($output, $body): mixed {
            $migrator = new Migrator(DB::manager(), $this->console()->path('database', 'migrations'), static function (string $direction, string $name, float $ms) use ($output): void {
                $output->writeln(sprintf('  %s <info>%s</info> <comment>(%.1f ms)</comment>', $direction === 'up' ? 'Migrated' : 'Reverted', $name, $ms));
            });

            return $body($migrator);
        });
    }

    /**
     * Like {@see withMigrator()}, but when the configured database does not exist yet, asks
     * whether to create it (the default answer, also used with --no-interaction, is yes), creates
     * it and runs `$body` again. Declining exits without changes.
     *
     * @template T
     *
     * @param callable(Migrator): T $body
     *
     * @return T|null null when the developer declined to create the database
     */
    protected function withMigratorCreatingDatabase(InputInterface $input, OutputInterface $output, callable $body): mixed
    {
        try {
            return $this->withMigrator($output, $body);
        } catch (Throwable $e) {
            if (!DatabaseCreator::isUnknownDatabase($e)) {
                throw $e;
            }
        }
        $section = $this->app()->config()['database'] ?? [];
        /** @var array<string, mixed> $section */
        $creator = new DatabaseCreator(is_array($section) ? $section : []);
        $output->writeln("<comment>The database '{$creator->name()}' does not exist.</comment>");
        $helper = new QuestionHelper();
        if ($helper->ask($input, $output, new ConfirmationQuestion('Would you like to create it? (yes/no) [yes] ', true)) !== true) {
            $output->writeln('Nothing was changed.');

            return null;
        }
        $creator->create();
        $output->writeln("<info>Created database '{$creator->name()}'.</info>");

        return $this->withMigrator($output, $body);
    }

    /** Runs a seeder class (inside the application's services). */
    protected function seed(OutputInterface $output, string $class): void
    {
        if (!class_exists($class) || !is_subclass_of($class, Seeder::class)) {
            throw new LogicException("Seeder {$class} not found (expected a subclass of " . Seeder::class . ')');
        }
        $this->app()->withServices(static function () use ($class, $output): void {
            $seeder = new $class();
            $seeder->setListener(static function (string $seeder) use ($output): void {
                $output->writeln("  Seeding <info>{$seeder}</info>");
            });
            $seeder->invoke();
        });
    }

    protected function addForceOption(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Allow the command when app.env is production');
    }

    /** Destructive commands refuse to run in production without --force. */
    protected function allowed(InputInterface $input, OutputInterface $output): bool
    {
        if ($this->isProduction() && $input->getOption('force') !== true) {
            $output->writeln('<error>The application is in production (app.env). Re-run with --force to continue.</error>');

            return false;
        }

        return true;
    }
}
