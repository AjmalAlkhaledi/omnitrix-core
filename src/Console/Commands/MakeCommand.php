<?php

declare(strict_types=1);

namespace Omnitrix\Console\Commands;

use Omnitrix\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function dirname;
use function file_exists;
use function file_put_contents;
use function is_dir;
use function is_string;
use function ltrim;
use function mkdir;
use function preg_match;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strrpos;
use function strtr;
use function substr;

/**
 * Base class for the generators. Class names may contain sub-namespaces with `/` or `\`
 * (`make:controller Admin/UserController` → `src/Controllers/Admin/UserController.php`).
 */
abstract class MakeCommand extends Command
{
    protected function configureName(string $description): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, $description)
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite an existing file');
    }

    protected function name(InputInterface $input): string
    {
        $name = $input->getArgument('name');
        if (!is_string($name) || preg_match('#^[A-Za-z_][A-Za-z0-9_]*([/\\\\][A-Za-z_][A-Za-z0-9_]*)*$#', $name) !== 1) {
            throw new RuntimeException('The name must be a class name, optionally with sub-namespaces (Admin/UserController)');
        }

        return str_replace('/', '\\', $name);
    }

    /**
     * Splits `Admin\UserController` into namespace suffix and class name.
     *
     * @return array{string, string}
     */
    protected static function split(string $name): array
    {
        $pos = strrpos($name, '\\');

        return $pos === false ? ['', $name] : ['\\' . substr($name, 0, $pos), substr($name, $pos + 1)];
    }

    /**
     * Writes a file from a template with `{{ placeholder }}` replacements.
     *
     * @param array<string, string> $replacements
     */
    protected function write(InputInterface $input, OutputInterface $output, string $path, string $template, array $replacements): bool
    {
        if (file_exists($path) && $input->getOption('force') !== true) {
            $output->writeln('<error>' . $this->relative($path) . ' already exists (use --force to overwrite)</error>');

            return false;
        }
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException("Could not create {$directory}");
        }
        $pairs = [];
        foreach ($replacements as $key => $value) {
            $pairs['{{ ' . $key . ' }}'] = $value;
        }
        if (file_put_contents($path, strtr($template, $pairs)) === false) {
            throw new RuntimeException("Could not write {$path}");
        }
        $output->writeln('<info>Created</info> ' . $this->relative($path));

        return true;
    }

    protected function relative(string $path): string
    {
        $base = $this->console()->path() . '/';

        return str_starts_with($path, $base) ? ltrim(substr($path, strlen($base)), '/') : $path;
    }
}
