<?php

declare(strict_types=1);

namespace Omnitrix\Console\Commands;

use Omnitrix\Database\Migrations\Migrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'migrate:reset', description: 'Revert every migration')]
final class MigrateResetCommand extends MigrationCommand
{
    protected function configure(): void
    {
        $this->addForceOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->allowed($input, $output)) {
            return self::FAILURE;
        }
        $reverted = $this->withMigrator($output, static fn(Migrator $m): array => $m->reset());
        $output->writeln($reverted === [] ? 'Nothing to reset.' : '<info>Reset complete.</info>');

        return self::SUCCESS;
    }
}
