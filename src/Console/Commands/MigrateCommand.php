<?php

declare(strict_types=1);

namespace Omnitrix\Console\Commands;

use Omnitrix\Database\Migrations\Migrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'migrate', description: 'Run the pending migrations')]
final class MigrateCommand extends MigrationCommand
{
    protected function configure(): void
    {
        $this->addOption('seed', null, InputOption::VALUE_NONE, 'Run the database seeder afterwards');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ran = $this->withMigratorCreatingDatabase($input, $output, static fn(Migrator $m): array => $m->migrate());
        if ($ran === null) {
            return self::FAILURE;
        }
        $output->writeln($ran === [] ? 'Nothing to migrate.' : '<info>Migrations complete.</info>');
        if ($input->getOption('seed') === true) {
            $this->seed($output, self::DEFAULT_SEEDER);
        }

        return self::SUCCESS;
    }
}
