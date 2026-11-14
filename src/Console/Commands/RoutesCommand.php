<?php

declare(strict_types=1);

namespace Omnitrix\Console\Commands;

use Closure;
use Omnitrix\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function count;
use function implode;
use function is_array;
use function is_object;
use function is_string;
use function strcmp;
use function usort;

#[AsCommand(name: 'routes', description: 'List the registered routes')]
final class RoutesCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $routes = $this->app()->router()->routes();
        usort($routes, static fn($a, $b): int => strcmp($a->pattern, $b->pattern));
        $rows = [];
        foreach ($routes as $route) {
            $rows[] = [implode('|', $route->methods), $route->pattern, $route->getName() ?? '', self::describe($route->handler), (string) count($route->getMiddleware())];
        }
        (new Table($output))->setHeaders(['Method', 'Path', 'Name', 'Handler', 'Route middleware'])->setRows($rows)->render();

        return self::SUCCESS;
    }

    private static function describe(mixed $handler): string
    {
        return match (true) {
            $handler instanceof Closure => 'Closure',
            is_string($handler) => $handler,
            is_array($handler) && isset($handler[0], $handler[1]) && is_string($handler[1]) => (is_object($handler[0]) ? $handler[0]::class : (is_string($handler[0]) ? $handler[0] : '?')) . '::' . $handler[1],
            is_object($handler) => $handler::class,
            default => '?',
        };
    }
}
