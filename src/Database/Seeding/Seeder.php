<?php

declare(strict_types=1);

namespace Omnitrix\Database\Seeding;

use InvalidArgumentException;

use function class_exists;
use function is_subclass_of;

/**
 * Fills the database with data. `php omnitrix db:seed` runs `Database\Seeds\DatabaseSeeder`,
 * which may call other seeders:
 *
 * ```php
 * final class DatabaseSeeder extends Seeder
 * {
 *     public function run(): void
 *     {
 *         $this->call(UserSeeder::class, PostSeeder::class);
 *     }
 * }
 * ```
 */
abstract class Seeder
{
    /** @var (callable(string): void)|null */
    private $listener = null;

    abstract public function run(): void;

    /** @param class-string<Seeder> ...$seeders */
    public function call(string ...$seeders): void
    {
        foreach ($seeders as $class) {
            if (!class_exists($class) || !is_subclass_of($class, self::class)) {
                throw new InvalidArgumentException("{$class} is not a seeder");
            }
            $seeder = new $class();
            $seeder->setListener($this->listener);
            $seeder->invoke();
        }
    }

    /** @internal runs the seeder and reports it to the listener */
    public function invoke(): void
    {
        if ($this->listener !== null) {
            ($this->listener)(static::class);
        }
        $this->run();
    }

    /**
     * @internal used by the console to report progress
     *
     * @param (callable(string): void)|null $listener
     */
    public function setListener(?callable $listener): void
    {
        $this->listener = $listener;
    }
}
