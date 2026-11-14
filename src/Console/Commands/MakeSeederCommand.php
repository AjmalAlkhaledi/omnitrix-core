<?php

declare(strict_types=1);

namespace Omnitrix\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function str_replace;

#[AsCommand(name: 'make:seeder', description: 'Create a seeder class in database/seeds')]
final class MakeSeederCommand extends MakeCommand
{
    private const string TEMPLATE = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Database\Seeds{{ namespace }};

        use Omnitrix\Database\Seeding\Seeder;

        final class {{ class }} extends Seeder
        {
            public function run(): void
            {
                //
            }
        }

        PHP;

    protected function configure(): void
    {
        $this->configureName('Seeder class name, e.g. UserSeeder');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $this->name($input);
        [$namespace, $class] = self::split($name);

        return $this->write($input, $output, $this->console()->path('database', 'seeds', str_replace('\\', '/', $name) . '.php'), self::TEMPLATE, ['namespace' => $namespace, 'class' => $class]) ? self::SUCCESS : self::FAILURE;
    }
}
