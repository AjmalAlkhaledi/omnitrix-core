<?php

declare(strict_types=1);

namespace Omnitrix\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function str_replace;

#[AsCommand(name: 'make:event', description: 'Create an event class in src/Events')]
final class MakeEventCommand extends MakeCommand
{
    private const string TEMPLATE = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Events{{ namespace }};

        /**
         * Dispatch with Event::dispatch(new {{ class }}(...)). Keep events small and serializable:
         * queued listeners receive a copy.
         */
        final readonly class {{ class }}
        {
            public function __construct()
            {
            }
        }

        PHP;

    protected function configure(): void
    {
        $this->configureName('Event class name, e.g. OrderShipped');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $this->name($input);
        [$namespace, $class] = self::split($name);

        return $this->write($input, $output, $this->console()->path('src', 'Events', str_replace('\\', '/', $name) . '.php'), self::TEMPLATE, ['namespace' => $namespace, 'class' => $class])
            ? self::SUCCESS
            : self::FAILURE;
    }
}
