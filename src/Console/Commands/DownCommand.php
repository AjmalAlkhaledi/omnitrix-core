<?php

declare(strict_types=1);

namespace Omnitrix\Console\Commands;

use LogicException;
use Omnitrix\Console\Command;
use Omnitrix\Facades\Facade;
use Omnitrix\Maintenance\MaintenanceMode;
use Omnitrix\Maintenance\PreventRequestsDuringMaintenance;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function bin2hex;
use function ctype_digit;
use function is_array;
use function is_string;
use function random_bytes;

/** Puts the application into maintenance mode: every request answers 503 until `up`. */
#[AsCommand(name: 'down', description: 'Put the application into maintenance mode')]
final class DownCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('message', null, InputOption::VALUE_REQUIRED, 'Message in the 503 response')
            ->addOption('retry', null, InputOption::VALUE_REQUIRED, 'Retry-After header in seconds')
            ->addOption('secret', null, InputOption::VALUE_REQUIRED, 'Requests with this X-Maintenance-Bypass header are still served')
            ->addOption('with-secret', null, InputOption::VALUE_NONE, 'Generate a bypass secret')
            ->addOption('allow', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Client IP still served (repeatable)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $message = $input->getOption('message');
        $retry = $input->getOption('retry');
        if ($retry !== null && (!is_string($retry) || !ctype_digit($retry))) {
            throw new LogicException('--retry must be a number of seconds');
        }
        $secret = $input->getOption('secret');
        $secret = is_string($secret) && $secret !== '' ? $secret : ($input->getOption('with-secret') === true ? bin2hex(random_bytes(16)) : null);
        $allow = $input->getOption('allow');
        $allowed = [];
        foreach (is_array($allow) ? $allow : [] as $ip) {
            if (is_string($ip) && $ip !== '') {
                $allowed[] = $ip;
            }
        }
        $this->app()->withServices(static function () use ($message, $retry, $secret, $allowed): void {
            self::mode()->activate(is_string($message) ? $message : null, $retry === null ? null : (int) $retry, $secret, $allowed);
        });
        $io->success('The application is down for maintenance.');
        if ($secret !== null) {
            $io->writeln('Bypass with the header: ' . PreventRequestsDuringMaintenance::BYPASS_HEADER . ': ' . $secret);
        }

        return self::SUCCESS;
    }

    public static function mode(): MaintenanceMode
    {
        $mode = Facade::container()?->get(MaintenanceMode::class);
        if (!$mode instanceof MaintenanceMode) {
            throw new LogicException('Maintenance mode is not available');
        }

        return $mode;
    }
}
