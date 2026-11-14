<?php

declare(strict_types=1);

namespace Omnitrix\Console\Commands;

use Omnitrix\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function str_replace;

#[AsCommand(name: 'make:model', description: 'Create a model class in src/Models')]
final class MakeModelCommand extends MakeCommand
{
    private const string TEMPLATE = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Models{{ namespace }};

        use DateTimeImmutable;
        use Omnitrix\Database\Model;

        /**
         * @property int                    $id
         * @property DateTimeImmutable|null $created_at
         * @property DateTimeImmutable|null $updated_at
         */
        final class {{ class }} extends Model
        {
            protected static ?string $table = '{{ table }}';

            /** @var list<string> */
            protected static array $fillable = [];

            /** @var list<string> */
            protected static array $hidden = [];

            /** @var array<string, string> */
            protected static array $casts = [];
        }

        PHP;

    protected function configure(): void
    {
        $this->configureName('Model class name, e.g. Post or Blog/Post');
        $this->addOption('migration', 'm', InputOption::VALUE_NONE, 'Also create a migration for the table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $this->name($input);
        [$namespace, $class] = self::split($name);
        $table = Str::plural(Str::snake($class));
        $path = $this->console()->path('src', 'Models', str_replace('\\', '/', $name) . '.php');
        if (!$this->write($input, $output, $path, self::TEMPLATE, ['namespace' => $namespace, 'class' => $class, 'table' => $table])) {
            return self::FAILURE;
        }
        if ($input->getOption('migration') === true) {
            $command = $this->getApplication()?->find('make:migration');
            if ($command !== null) {
                return $command->run(new ArrayInput(['name' => "create_{$table}_table", '--create' => $table]), $output);
            }
        }

        return self::SUCCESS;
    }
}
