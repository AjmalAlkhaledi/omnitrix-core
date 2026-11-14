<?php

declare(strict_types=1);

namespace Omnitrix\Console\Commands;

use Omnitrix\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function is_numeric;
use function is_scalar;
use function is_string;
use function putenv;

/**
 * Starts the HTTP server in the foreground. `--host`, `--port` and `--workers` set the
 * `SERVER_HOST`, `SERVER_PORT` and `SERVER_WORKERS` environment variables before the
 * application is built, so `config/server.php` picks them up.
 */
#[AsCommand(name: 'serve', description: 'Start the HTTP server')]
final class ServeCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('host', null, InputOption::VALUE_REQUIRED, 'Listen address (SERVER_HOST)')
            ->addOption('port', 'p', InputOption::VALUE_REQUIRED, 'Listen port (SERVER_PORT)')
            ->addOption('workers', 'w', InputOption::VALUE_REQUIRED, 'Worker processes (SERVER_WORKERS)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach (['host' => 'SERVER_HOST', 'port' => 'SERVER_PORT', 'workers' => 'SERVER_WORKERS'] as $option => $variable) {
            $value = $input->getOption($option);
            if (is_string($value) && $value !== '') {
                if ($option !== 'host' && !is_numeric($value)) {
                    $output->writeln("<error>--{$option} must be a number</error>");

                    return self::INVALID;
                }
                putenv("{$variable}={$value}");
                $_ENV[$variable] = $value;
            }
        }
        $app = $this->app();
        $text = static fn(mixed $v): string => is_scalar($v) ? (string) $v : '';
        $output->writeln('<info>Omnitrix server listening on http://' . $text($this->config('server.host', '127.0.0.1')) . ':' . $text($this->config('server.port', 8080)) . '</info>  (stop with Ctrl+C)');
        $app->run();

        return self::SUCCESS;
    }
}
