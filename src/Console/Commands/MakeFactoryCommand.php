<?php

declare(strict_types=1);

namespace Omnitrix\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function is_string;
use function preg_replace;
use function str_replace;

#[AsCommand(name: 'make:factory', description: 'Create a model factory in database/factories')]
final class MakeFactoryCommand extends MakeCommand
{
    private const string TEMPLATE = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Database\Factories;

        use App\Models\{{ model }};
        use Omnitrix\Database\Factories\Factory;

        /** {{ model }}::factory()->count(3)->create() (add the HasFactory trait to the model). */
        final class {{ class }} extends Factory
        {
            protected string $model = {{ model }}::class;

            public function definition(): array
            {
                return [
                    'name' => $this->faker->name(),
                ];
            }
        }

        PHP;

    protected function configure(): void
    {
        $this->configureName('Factory class name, e.g. UserFactory');
        $this->addOption('model', 'm', InputOption::VALUE_REQUIRED, 'Model it builds (default: the name without "Factory")');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $this->name($input);
        [, $class] = self::split($name);
        $option = $input->getOption('model');
        $model = is_string($option) && $option !== '' ? $option : (string) preg_replace('/Factory$/', '', $class);

        return $this->write($input, $output, $this->console()->path('database', 'factories', str_replace('\\', '/', $class) . '.php'), self::TEMPLATE, ['class' => $class, 'model' => $model])
            ? self::SUCCESS
            : self::FAILURE;
    }
}
