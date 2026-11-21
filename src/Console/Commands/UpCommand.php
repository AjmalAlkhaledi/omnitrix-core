<?php

declare(strict_types=1);

namespace Omnitrix\Console\Commands;

use Omnitrix\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Brings the application out of maintenance mode. */
#[AsCommand(name: 'up', description: 'Bring the application out of maintenance mode')]
final class UpCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $wasDown = $this->app()->withServices(static function (): bool {
            $mode = DownCommand::mode();
            $down = $mode->active();
            $mode->deactivate();

            return $down;
        });
        (new SymfonyStyle($input, $output))->success($wasDown ? 'The application is up.' : 'The application was not down.');

        return self::SUCCESS;
    }
}
