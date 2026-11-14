<?php

declare(strict_types=1);

namespace Omnitrix\Console\Commands;

use Omnitrix\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function str_replace;

#[AsCommand(name: 'make:job', description: 'Create a background job class in src/Jobs')]
final class MakeJobCommand extends MakeCommand
{
    private const string TEMPLATE = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Jobs{{ namespace }};

        use Omnitrix\Core\Queue\Job;

        /**
         * Dispatch with Queue::dispatch('{{ job }}', [...]); register it in config/queue.php.
         * Jobs can run more than once (at-least-once delivery): keep the handler idempotent.
         */
        final class {{ class }}
        {
            /** @param array<string, mixed> $payload */
            public function handle(array $payload, Job $job): void
            {
                //
            }
        }

        PHP;

    protected function configure(): void
    {
        $this->configureName('Job class name, e.g. SendWelcomeEmail');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $this->name($input);
        [$namespace, $class] = self::split($name);
        $job = str_replace('_', '.', Str::snake($class));
        if (!$this->write($input, $output, $this->console()->path('src', 'Jobs', str_replace('\\', '/', $name) . '.php'), self::TEMPLATE, ['namespace' => $namespace, 'class' => $class, 'job' => $job])) {
            return self::FAILURE;
        }
        $output->writeln("\nRegister it in config/queue.php:\n\n    'jobs' => ['{$job}' => \\App\\Jobs\\{$name}::class],");

        return self::SUCCESS;
    }
}
