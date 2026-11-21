<?php

declare(strict_types=1);

namespace Omnitrix\Database\Migrations;

use Omnitrix\Core\Database\Database;
use Omnitrix\Core\Database\DatabaseManager;
use Omnitrix\Database\Schema\Blueprint;
use Omnitrix\Database\Schema\Schema;
use RuntimeException;

use function array_diff;
use function array_keys;
use function array_values;
use function basename;
use function glob;
use function hrtime;
use function in_array;
use function is_dir;
use function is_int;
use function is_numeric;
use function is_string;
use function max;
use function sort;

/**
 * Runs migration files in filename order and records each one, with a batch number, in the
 * `migrations` table of the default connection. A rollback reverts the most recent batch.
 *
 * MySQL and MariaDB commit DDL statements implicitly, so a migration that fails halfway leaves
 * the statements it already ran in place; keep each migration small. On PostgreSQL each
 * migration and its record run in one transaction.
 */
final class Migrator
{
    public const string TABLE = 'migrations';

    /** @var (callable(string, string, float): void)|null */
    private $listener;

    public function __construct(
        private readonly DatabaseManager $databases,
        private readonly string $path,
        ?callable $listener = null,
    ) {
        $this->listener = $listener;
    }

    /**
     * Runs every pending migration in one new batch.
     *
     * @return list<string> names of the migrations that ran
     */
    public function migrate(): array
    {
        $this->ensureRepository();
        $files = $this->files();
        $pending = array_values(array_diff(array_keys($files), $this->ran()));
        if ($pending === []) {
            return [];
        }
        $batch = $this->lastBatch() + 1;
        foreach ($pending as $name) {
            $this->apply($name, $files[$name], 'up', fn(): int => $this->repository()->table(self::TABLE)->insert(['migration' => $name, 'batch' => $batch]));
        }

        return $pending;
    }

    /**
     * Reverts the last `$steps` batches (most recent first).
     *
     * @return list<string> names of the migrations that were reverted
     */
    public function rollback(int $steps = 1): array
    {
        $this->ensureRepository();
        $last = $this->lastBatch();
        if ($last === 0) {
            return [];
        }
        $rows = $this->repository()->table(self::TABLE)->where('batch', '>', max(0, $last - $steps))->orderByDesc('id')->get();

        return $this->revert($rows);
    }

    /**
     * Reverts every migration that ran.
     *
     * @return list<string>
     */
    public function reset(): array
    {
        $this->ensureRepository();

        return $this->revert($this->repository()->table(self::TABLE)->orderByDesc('id')->get());
    }

    /**
     * Drops every table of the default connection, then runs all migrations.
     *
     * @return list<string>
     */
    public function fresh(): array
    {
        (new Schema($this->repository()))->dropAllTables();

        return $this->migrate();
    }

    /**
     * @return list<array{migration: string, ran: bool, batch: ?int}>
     */
    public function status(): array
    {
        $this->ensureRepository();
        $batches = [];
        foreach ($this->repository()->table(self::TABLE)->get() as $row) {
            if (is_string($row['migration'] ?? null)) {
                $batches[$row['migration']] = is_numeric($row['batch'] ?? null) ? (int) $row['batch'] : null;
            }
        }
        $status = [];
        $names = array_keys($this->files());
        foreach (array_keys($batches) as $name) {
            if (!in_array($name, $names, true)) {
                $names[] = $name;
            }
        }
        sort($names);
        foreach ($names as $name) {
            $status[] = ['migration' => $name, 'ran' => isset($batches[$name]), 'batch' => $batches[$name] ?? null];
        }

        return $status;
    }

    /** @return array<string, string> migration name => file path, in run order */
    public function files(): array
    {
        if (!is_dir($this->path)) {
            return [];
        }
        $paths = glob($this->path . '/*.php');
        $paths = $paths === false ? [] : $paths;
        sort($paths);
        $files = [];
        foreach ($paths as $path) {
            $files[basename($path, '.php')] = $path;
        }

        return $files;
    }

    /** @return list<string> */
    public function ran(): array
    {
        $this->ensureRepository();
        $names = [];
        foreach ($this->repository()->table(self::TABLE)->orderBy('id')->get() as $row) {
            if (is_string($row['migration'] ?? null)) {
                $names[] = $row['migration'];
            }
        }

        return $names;
    }

    public function ensureRepository(): void
    {
        $schema = new Schema($this->repository());
        if ($schema->hasTable(self::TABLE)) {
            return;
        }
        $schema->create(self::TABLE, static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('migration')->unique();
            $table->integer('batch');
            $table->timestamp('ran_at')->useCurrent();
        });
    }

    /**
     * @param list<array<string, mixed>> $rows repository rows, in the order to revert
     *
     * @return list<string>
     */
    private function revert(array $rows): array
    {
        $files = $this->files();
        $reverted = [];
        foreach ($rows as $row) {
            $name = $row['migration'] ?? null;
            if (!is_string($name)) {
                continue;
            }
            if (!isset($files[$name])) {
                throw new RuntimeException("Migration file for '{$name}' not found in {$this->path}");
            }
            $this->apply($name, $files[$name], 'down', fn(): int => $this->repository()->table(self::TABLE)->where('migration', $name)->delete());
            $reverted[] = $name;
        }

        return $reverted;
    }

    /**
     * Runs one migration and `$record` (its row in the migrations table). PostgreSQL runs DDL in
     * transactions: when the migration uses the repository's connection, both happen atomically,
     * so a failing migration leaves nothing behind.
     *
     * @param 'up'|'down'    $direction
     * @param callable(): int $record
     */
    private function apply(string $name, string $file, string $direction, callable $record): void
    {
        $migration = (static fn(string $path): mixed => require $path)($file);
        if (!$migration instanceof Migration) {
            throw new RuntimeException("Migration file {$file} must return an instance of " . Migration::class);
        }
        $db = $this->connection($migration->connection);
        $schema = new Schema($db);
        $start = hrtime(true);
        $run = static function () use ($migration, $schema, $direction, $record): void {
            $direction === 'up' ? $migration->up($schema) : $migration->down($schema);
            $record();
        };
        if ($db->driverName() === 'pgsql' && $db === $this->repository()) {
            $db->transaction($run);
        } else {
            $run();
        }
        if ($this->listener !== null) {
            ($this->listener)($direction, $name, (hrtime(true) - $start) / 1e6);
        }
    }

    private function lastBatch(): int
    {
        $max = $this->repository()->table(self::TABLE)->max('batch');

        return is_int($max) ? $max : (is_numeric($max) ? (int) $max : 0);
    }

    private function repository(): Database
    {
        return $this->databases->connection();
    }

    private function connection(?string $name): Database
    {
        return $this->databases->connection($name);
    }
}
