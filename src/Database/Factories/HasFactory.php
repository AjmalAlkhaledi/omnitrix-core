<?php

declare(strict_types=1);

namespace Omnitrix\Database\Factories;

use LogicException;

use function class_exists;
use function is_subclass_of;
use function property_exists;
use function strrchr;
use function substr;

/**
 * `User::factory()` for models whose factory is `Database\Factories\UserFactory` (or set
 * `protected static string $factory = UserFactory::class;`).
 */
trait HasFactory
{
    /** @return Factory */
    public static function factory(?int $count = null): Factory
    {
        $class = property_exists(static::class, 'factory') ? static::$factory : 'Database\\Factories\\' . substr((string) strrchr('\\' . static::class, '\\'), 1) . 'Factory';
        if (!class_exists($class) || !is_subclass_of($class, Factory::class)) {
            throw new LogicException('No factory for ' . static::class . " (expected {$class})");
        }
        $factory = $class::new();

        return $count === null ? $factory : $factory->count($count);
    }
}
