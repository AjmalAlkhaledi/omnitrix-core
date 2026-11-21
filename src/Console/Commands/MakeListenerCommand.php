<?php

declare(strict_types=1);

namespace Omnitrix\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function is_string;
use function ltrim;
use function str_replace;

#[AsCommand(name: 'make:listener', description: 'Create an event listener class in src/Listeners')]
final class MakeListenerCommand extends MakeCommand
{
    private const string TEMPLATE = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Listeners{{ namespace }};

        {{ imports }}
        final class {{ class }}{{ implements }}
        {
            public function handle({{ event }} $event): void
            {
                //
            }
        }

        PHP;

    protected function configure(): void
    {
        $this->configureName('Listener class name, e.g. SendShipmentEmail');
        $this->addOption('event', 'e', InputOption::VALUE_REQUIRED, 'Event class it listens to, e.g. OrderShipped')
            ->addOption('queued', null, InputOption::VALUE_NONE, 'Run the listener in the queue worker');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $this->name($input);
        [$namespace, $class] = self::split($name);
        $event = $input->getOption('event');
        $eventClass = is_string($event) && $event !== '' ? 'App\Events\\' . ltrim(str_replace('/', '\\', $event), '\\') : 'object';
        $short = $eventClass === 'object' ? 'object' : self::split(ltrim(str_replace('App\Events\\', '', $eventClass), '\\'))[1];
        $queued = $input->getOption('queued') === true;
        $imports = ($eventClass === 'object' ? '' : "use {$eventClass};\n") . ($queued ? "use Omnitrix\\Events\\ShouldQueue;\n" : '');
        $ok = $this->write($input, $output, $this->console()->path('src', 'Listeners', str_replace('\\', '/', $name) . '.php'), self::TEMPLATE, [
            'namespace' => $namespace,
            'class' => $class,
            'imports' => $imports === '' ? '' : $imports . "\n",
            'implements' => $queued ? ' implements ShouldQueue' : '',
            'event' => $short,
        ]);
        if ($ok && $eventClass !== 'object') {
            $output->writeln("\nRegister it in config/events.php:\n\n    'listen' => [\\{$eventClass}::class => [\\App\\Listeners\\{$name}::class]],");
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
