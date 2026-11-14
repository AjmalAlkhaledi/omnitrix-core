<?php

declare(strict_types=1);

namespace Omnitrix\Console\Commands;

use Omnitrix\Database\Migrations\Migrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function ctype_digit;
use function is_string;

#[AsCommand(name: 'migrate:rollback', description: 'Revert the last batch of migrations')]
final class MigrateRollbackCommand extends MigrationCommand
{
    protected function configure(): void
    {
        $this->addOption('step', null, InputOption::VALUE_REQUIRED, 'Number of batches to revert', '1');
        $this->addForceOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $step = $input->getOption('step');
        if (!is_string($step) || !ctype_digit($step) || (int) $step < 1) {
            $output->writeln('<error>--step must be a positive integer</error>');

            return self::INVALID;
        }
        if (!$this->allowed($input, $output)) {
            return self::FAILURE;
        }
        $reverted = $this->withMigrator($output, static fn(Migrator $m): array => $m->rollback((int) $step));
        $output->writeln($reverted === [] ? 'Nothing to roll back.' : '<info>Rollback complete.</info>');

        return self::SUCCESS;
    }
}
