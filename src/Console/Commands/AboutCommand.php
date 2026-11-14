<?php

declare(strict_types=1);

namespace Omnitrix\Console\Commands;

use Omnitrix\Console\Command;
use Omnitrix\Console\Console;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
use function is_array;
use function is_scalar;
use function phpversion;

use const PHP_VERSION;

#[AsCommand(name: 'about', description: 'Show versions, environment and configuration summary')]
final class AboutCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $app = $this->app();
        $config = $app->config();
        $text = static fn(mixed $v): string => is_scalar($v) ? (string) $v : '-';
        $database = $config['database'] ?? null;
        $io->definitionList(
            ['Omnitrix' => Console::version()],
            ['PHP' => PHP_VERSION],
            ['OpenSwoole' => self::extension('openswoole')],
            ['phpredis' => self::extension('redis')],
            ['Environment' => $text($this->config('app.env', 'production'))],
            ['Debug' => $app->isDebug() ? 'enabled' : 'disabled'],
            ['Server' => $text($this->config('server.host', '127.0.0.1')) . ':' . $text($this->config('server.port', 8080)) . ' (' . $text($this->config('server.workers', 'auto')) . ' workers)'],
            ['Database' => is_array($database) && $database !== [] ? $text($this->config('database.host', '-')) . '/' . $text($this->config('database.database', '-')) : 'not configured'],
            ['Redis' => isset($config['redis']) ? $text($this->config('redis.host', '127.0.0.1')) . ':' . $text($this->config('redis.port', 6379)) : 'not configured'],
            ['Routes' => (string) count($app->router()->routes())],
        );

        return self::SUCCESS;
    }

    private static function extension(string $name): string
    {
        $version = phpversion($name);

        return $version === false ? 'not loaded' : $version;
    }
}
