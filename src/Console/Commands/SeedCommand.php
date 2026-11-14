<?php

declare(strict_types=1);

namespace Omnitrix\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function is_string;
use function str_contains;

#[AsCommand(name: 'db:seed', description: 'Fill the database using a seeder')]
final class SeedCommand extends MigrationCommand
{
    protected function configure(): void
    {
        $this->addOption('class', null, InputOption::VALUE_REQUIRED, 'Seeder class (short names resolve in Database\\Seeds)', self::DEFAULT_SEEDER);
        $this->addForceOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->allowed($input, $output)) {
            return self::FAILURE;
        }
        $class = $input->getOption('class');
        $class = is_string($class) && $class !== '' ? $class : self::DEFAULT_SEEDER;
        if (!str_contains($class, '\\')) {
            $class = 'Database\\Seeds\\' . $class;
        }
        $this->seed($output, $class);
        $output->writeln('<info>Seeding complete.</info>');

        return self::SUCCESS;
    }
}
