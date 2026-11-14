<?php

declare(strict_types=1);

namespace Omnitrix\Console\Commands;

use Omnitrix\Database\Migrations\Migrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function array_map;

#[AsCommand(name: 'migrate:status', description: 'Show which migrations have run')]
final class MigrateStatusCommand extends MigrationCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $status = $this->withMigrator($output, static fn(Migrator $m): array => $m->status());
        if ($status === []) {
            $output->writeln('No migrations found.');

            return self::SUCCESS;
        }
        (new Table($output))
            ->setHeaders(['Migration', 'Ran', 'Batch'])
            ->setRows(array_map(static fn(array $row): array => [$row['migration'], $row['ran'] ? '<info>yes</info>' : '<comment>pending</comment>', $row['batch'] === null ? '' : (string) $row['batch']], $status))
            ->render();

        return self::SUCCESS;
    }
}
