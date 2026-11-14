<?php

declare(strict_types=1);

namespace Omnitrix\Database\Relations;

use BadMethodCallException;
use Omnitrix\Database\Collection;
use Omnitrix\Database\EagerLoader;
use Omnitrix\Database\Model;
use Omnitrix\Database\ModelQuery;

use function array_values;
use function is_callable;
use function is_int;
use function is_string;

/**
 * A relationship between a model and other models. Calling the relation method returns a
 * query constrained to the related rows (`$user->posts()->where('published', true)->get()`);
 * reading it as a property loads and caches the result (`$user->posts`).
 *
 * @template TRelated of Model
 *
 * @mixin ModelQuery<TRelated>
 */
abstract class Relation
{
    /** @var ModelQuery<TRelated>|null */
    private ?ModelQuery $constrained = null;

    /** @param class-string<TRelated> $related */
    public function __construct(protected readonly Model $parent, protected readonly string $related) {}

    /**
     * The related models for the parent: a model or null for single relations, a collection for
     * many relations.
     *
     * @return TRelated|Collection<TRelated>|null
     */
    abstract public function getResults(): Model|Collection|null;

    /**
     * Restricts `$query` to the rows related to any of `$parents`.
     *
     * @param ModelQuery<TRelated> $query
     * @param Collection<covariant Model>    $parents
     */
    abstract protected function addEagerConstraints(ModelQuery $query, Collection $parents): void;

    /**
     * Assigns the eager-loaded `$results` to each parent under `$name`.
     *
     * @param Collection<covariant Model>    $parents
     * @param Collection<TRelated> $results
     */
    abstract protected function match(Collection $parents, Collection $results, string $name): void;

    /**
     * Restricts `$query` to the rows related to this relation's parent.
     *
     * @param ModelQuery<TRelated> $query
     */
    abstract protected function addConstraints(ModelQuery $query): void;

    /** @return ModelQuery<TRelated> the query for this parent's related rows */
    public function query(): ModelQuery
    {
        $query = $this->constrained;
        if ($query === null) {
            $query = $this->newQuery();
            $this->addConstraints($query);
            $this->constrained = $query;
        }

        return $query;
    }

    /** @return Collection<TRelated> */
    public function get(): Collection
    {
        return $this->query()->get();
    }

    /** @return TRelated|null */
    public function first(): ?Model
    {
        return $this->query()->first();
    }

    /**
     * @internal loads this relation for many parents with one query
     *
     * @param Collection<covariant Model>                         $parents
     * @param (callable(ModelQuery<covariant Model>): mixed)|null $constraint
     * @param array<string, mixed>                      $nested
     */
    public function eagerLoad(Collection $parents, string $name, ?callable $constraint, array $nested): void
    {
        $query = $this->newQuery();
        $this->addEagerConstraints($query, $parents);
        if ($constraint !== null) {
            $constraint($query);
        }
        $results = $query->get();
        if ($nested !== [] && $results->isNotEmpty()) {
            EagerLoader::load($results, $nested);
        }
        $this->match($parents, $results, $name);
    }

    /** @param list<mixed> $arguments */
    public function __call(string $method, array $arguments): mixed
    {
        $query = $this->query();
        $callable = [$query, $method];
        if (!is_callable($callable)) {
            throw new BadMethodCallException('Call to undefined method ' . static::class . "::{$method}()");
        }
        $result = $callable(...$arguments);

        return $result === $query ? $this : $result;
    }

    /** @return ModelQuery<TRelated> */
    protected function newQuery(): ModelQuery
    {
        $class = $this->related;

        return $class::query();
    }

    /**
     * Distinct non-null values of `$attribute` across `$models`.
     *
     * @param Collection<covariant Model> $models
     *
     * @return list<int|string>
     */
    protected static function keys(Collection $models, string $attribute): array
    {
        $keys = [];
        foreach ($models as $model) {
            $value = $model->getAttributes()[$attribute] ?? null;
            if (is_int($value) || is_string($value)) {
                $keys[(string) $value] = $value;
            }
        }

        return array_values($keys);
    }

    protected static function keyString(mixed $value): ?string
    {
        return is_int($value) || is_string($value) ? (string) $value : null;
    }
}
