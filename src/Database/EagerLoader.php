<?php

declare(strict_types=1);

namespace Omnitrix\Database;

use InvalidArgumentException;
use Omnitrix\Database\Relations\Relation;

use function array_shift;
use function explode;
use function implode;
use function is_callable;
use function is_int;
use function is_string;
use function method_exists;

/**
 * Loads relations for a set of models with one query per relation (and per nesting level),
 * avoiding one query per model.
 *
 * @internal used by {@see ModelQuery::with()} and {@see Collection::load()}
 *
 * @phpstan-type Tree array<string, array{constraint: (callable(ModelQuery<covariant Model>): mixed)|null, nested: array<string, mixed>}>
 */
final class EagerLoader
{
    /**
     * Normalises `'posts'`, `'posts.comments'`, `['posts' => fn ($q) => ...]` and lists of those.
     *
     * @param array<int|string, string|array<int|string, string|callable(ModelQuery<covariant Model>): mixed>> $relations
     *
     * @return array<string, array{constraint: (callable(ModelQuery<covariant Model>): mixed)|null, nested: array<string, mixed>}>
     */
    public static function parse(array $relations): array
    {
        $tree = [];
        foreach ($relations as $relation) {
            $entries = is_string($relation) ? [$relation] : $relation;
            foreach ($entries as $key => $value) {
                if (is_int($key)) {
                    if (!is_string($value)) {
                        throw new InvalidArgumentException('Relation names must be strings');
                    }
                    self::add($tree, $value, null);
                } else {
                    if (!is_callable($value)) {
                        throw new InvalidArgumentException("Constraint for relation '{$key}' must be callable");
                    }
                    self::add($tree, $key, $value);
                }
            }
        }

        return $tree;
    }

    /**
     * @param Collection<covariant Model> $models
     * @param array<string, mixed>        $tree   result of {@see parse()}
     */
    public static function load(Collection $models, array $tree): void
    {
        $first = $models->first();
        if ($first === null) {
            return;
        }
        foreach ($tree as $name => $spec) {
            $method = [$first, $name];
            if (!method_exists($first, $name) || !is_callable($method)) {
                throw new InvalidArgumentException('Undefined relation ' . $first::class . "::{$name}()");
            }
            $relation = $method();
            if (!$relation instanceof Relation) {
                throw new InvalidArgumentException($first::class . "::{$name}() does not return a relation");
            }
            /** @var array{constraint: (callable(ModelQuery<covariant Model>): mixed)|null, nested: array<string, mixed>} $spec */
            $relation->eagerLoad($models, $name, $spec['constraint'], $spec['nested']);
        }
    }

    /**
     * @param array<string, array{constraint: (callable(ModelQuery<covariant Model>): mixed)|null, nested: array<string, mixed>}> $tree
     * @param (callable(ModelQuery<covariant Model>): mixed)|null $constraint
     */
    private static function add(array &$tree, string $path, ?callable $constraint): void
    {
        $segments = explode('.', $path);
        $name = array_shift($segments);
        if ($name === '') {
            throw new InvalidArgumentException("Invalid relation path '{$path}'");
        }
        $tree[$name] ??= ['constraint' => null, 'nested' => []];
        if ($segments === []) {
            $tree[$name]['constraint'] = $constraint ?? $tree[$name]['constraint'];

            return;
        }
        /** @var array<string, array{constraint: (callable(ModelQuery<covariant Model>): mixed)|null, nested: array<string, mixed>}> $nested */
        $nested = $tree[$name]['nested'];
        self::add($nested, implode('.', $segments), $constraint);
        $tree[$name]['nested'] = $nested;
    }
}
