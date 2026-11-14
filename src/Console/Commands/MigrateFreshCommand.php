<?php

declare(strict_types=1);

namespace Omnitrix\Console\Commands;

use Omnitrix\Database\Migrations\Migrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'migrate:fresh', description: 'Drop every table and run all migrations')]
final class MigrateFreshCommand extends MigrationCommand
{
    protected function configure(): void
    {
        $this->addOption('seed', null, InputOption::VALUE_NONE, 'Run the database seeder afterwards');
        $this->addForceOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->allowed($input, $output)) {
            return self::FAILURE;
        }
        $ran = $this->withMigratorCreatingDatabase($input, $output, static fn(Migrator $m): array => $m->fresh());
        if ($ran === null) {
            return self::FAILURE;
        }
        $output->writeln('<info>Dropped all tables and ran the migrations.</info>');
        if ($input->getOption('seed') === true) {
            $this->seed($output, self::DEFAULT_SEEDER);
        }

        return self::SUCCESS;
    }
}
