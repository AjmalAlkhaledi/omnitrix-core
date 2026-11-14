<?php

declare(strict_types=1);

namespace Omnitrix\Console\Commands;

use DateTimeImmutable;
use Omnitrix\Console\Command;
use Omnitrix\Support\Str;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function basename;
use function dirname;
use function file_put_contents;
use function glob;
use function is_dir;
use function is_string;
use function mkdir;
use function preg_match;
use function strtr;

#[AsCommand(name: 'make:migration', description: 'Create a migration file in database/migrations')]
final class MakeMigrationCommand extends Command
{
    private const string CREATE = <<<'PHP'
        <?php

        declare(strict_types=1);

        use Omnitrix\Database\Migrations\Migration;
        use Omnitrix\Database\Schema\Blueprint;
        use Omnitrix\Database\Schema\Schema;

        return new class extends Migration {
            public function up(Schema $schema): void
            {
                $schema->create('{{ table }}', static function (Blueprint $table): void {
                    $table->id();
                    $table->timestamps();
                });
            }

            public function down(Schema $schema): void
            {
                $schema->dropIfExists('{{ table }}');
            }
        };

        PHP;

    private const string UPDATE = <<<'PHP'
        <?php

        declare(strict_types=1);

        use Omnitrix\Database\Migrations\Migration;
        use Omnitrix\Database\Schema\Blueprint;
        use Omnitrix\Database\Schema\Schema;

        return new class extends Migration {
            public function up(Schema $schema): void
            {
                $schema->table('{{ table }}', static function (Blueprint $table): void {
                    // $table->string('nickname')->nullable()->after('name');
                });
            }

            public function down(Schema $schema): void
            {
                $schema->table('{{ table }}', static function (Blueprint $table): void {
                    // $table->dropColumn('nickname');
                });
            }
        };

        PHP;

    private const string BLANK = <<<'PHP'
        <?php

        declare(strict_types=1);

        use Omnitrix\Database\Migrations\Migration;
        use Omnitrix\Database\Schema\Schema;

        return new class extends Migration {
            public function up(Schema $schema): void
            {
                //
            }

            public function down(Schema $schema): void
            {
                //
            }
        };

        PHP;

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Migration name in snake case, e.g. create_posts_table or add_status_to_orders')
            ->addOption('create', null, InputOption::VALUE_REQUIRED, 'Table to create')
            ->addOption('table', null, InputOption::VALUE_REQUIRED, 'Existing table to change');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        if (!is_string($name) || preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new RuntimeException('The migration name may contain letters, digits and underscores only');
        }
        $name = Str::snake($name);
        $directory = $this->console()->path('database', 'migrations');
        $existing = glob($directory . '/*_' . $name . '.php');
        if ($existing !== false && $existing !== []) {
            $output->writeln("<error>A migration named {$name} already exists</error>");

            return self::FAILURE;
        }
        $create = $input->getOption('create');
        $table = $input->getOption('table');
        if (!is_string($create) && !is_string($table) && preg_match('/^create_(\w+)_table$/', $name, $m) === 1) {
            $create = $m[1];
        }
        [$template, $tableName] = match (true) {
            is_string($create) && $create !== '' => [self::CREATE, $create],
            is_string($table) && $table !== '' => [self::UPDATE, $table],
            default => [self::BLANK, ''],
        };
        $path = $directory . '/' . (new DateTimeImmutable())->format('Y_m_d_His') . '_' . $name . '.php';
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0o775, true) && !is_dir(dirname($path))) {
            throw new RuntimeException('Could not create ' . dirname($path));
        }
        if (file_put_contents($path, strtr($template, ['{{ table }}' => $tableName])) === false) {
            throw new RuntimeException("Could not write {$path}");
        }
        $output->writeln('<info>Created</info> database/migrations/' . basename($path));

        return self::SUCCESS;
    }
}
