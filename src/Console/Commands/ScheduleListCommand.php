<?php

declare(strict_types=1);

namespace Omnitrix\Console\Commands;

use DateTimeImmutable;
use Omnitrix\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Lists the scheduled tasks with their next run. */
#[AsCommand(name: 'schedule:list', description: 'List the scheduled tasks')]
final class ScheduleListCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = new DateTimeImmutable();
        $rows = [];
        foreach ($this->app()->schedule()->tasks() as $task) {
            $rows[] = [$task->expression(), $task->label(), $task->nextRunAfter($now)->format('Y-m-d H:i T')];
        }
        $io = new SymfonyStyle($input, $output);
        $rows === [] ? $io->writeln('No tasks are scheduled.') : $io->table(['Cron', 'Task', 'Next run'], $rows);

        return self::SUCCESS;
    }
}
