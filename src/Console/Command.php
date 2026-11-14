<?php

declare(strict_types=1);

namespace Omnitrix\Console;

use LogicException;
use Omnitrix\Application;
use Omnitrix\Config\Config;
use Symfony\Component\Console\Command\Command as BaseCommand;

/**
 * Base class for project commands. Gives access to the project ({@see Console::path()}) and to
 * the application built by `bootstrap.php`. Use {@see Application::withServices()} to reach the
 * database, Redis and the other services from a command.
 */
abstract class Command extends BaseCommand
{
    private ?Console $console = null;

    public function __construct(?Console $console = null)
    {
        $this->console = $console;
        parent::__construct();
    }

    /** @internal */
    public function setConsole(Console $console): void
    {
        $this->console = $console;
    }

    protected function console(): Console
    {
        return $this->console ?? throw new LogicException('The command is not attached to a console');
    }

    protected function app(): Application
    {
        return $this->console()->app();
    }

    protected function config(string $path, mixed $default = null): mixed
    {
        return Config::get($this->app()->config(), $path, $default);
    }

    protected function isProduction(): bool
    {
        return Config::string($this->app()->config(), 'app.env', 'production') === 'production';
    }
}
