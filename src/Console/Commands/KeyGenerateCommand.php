<?php

declare(strict_types=1);

namespace Omnitrix\Console\Commands;

use Omnitrix\Console\Command;
use Omnitrix\Encryption\AesEncrypter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_string;
use function preg_match;
use function preg_replace;
use function rtrim;
use function str_replace;

/**
 * Writes a new random `APP_KEY` into `.env` (the key behind encryption, encrypted cookies,
 * sessions and signed URLs). An existing key is kept unless `--force` is given: replacing it
 * makes data encrypted with the old key unreadable (list the old key in `app.previous_keys`).
 */
#[AsCommand(name: 'key:generate', description: 'Set a new random application key (APP_KEY) in .env')]
final class KeyGenerateCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('show', null, InputOption::VALUE_NONE, 'Print a key without changing .env')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Replace an existing key');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $key = AesEncrypter::generateKey();
        if ($input->getOption('show') === true) {
            $output->writeln($key);

            return self::SUCCESS;
        }
        $file = $this->console()->path('.env');
        $contents = file_exists($file) ? file_get_contents($file) : '';
        $contents = is_string($contents) ? $contents : '';
        if (preg_match('/^APP_KEY=(.+)$/m', $contents) === 1 && $input->getOption('force') !== true) {
            $io->warning('APP_KEY is already set. Replacing it makes encrypted data unreadable; use --force to replace it anyway.');

            return self::FAILURE;
        }
        $line = 'APP_KEY=' . $key;
        $contents = preg_match('/^APP_KEY=.*$/m', $contents) === 1
            ? (string) preg_replace('/^APP_KEY=.*$/m', str_replace(['\\', '$'], ['\\\\', '\$'], $line), $contents)
            : rtrim($contents, "\n") . ($contents === '' ? '' : "\n") . $line . "\n";
        file_put_contents($file, $contents);
        $io->success('Application key set in .env');

        return self::SUCCESS;
    }
}
