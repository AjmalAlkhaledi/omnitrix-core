<?php

declare(strict_types=1);

namespace Omnitrix\Database\Relations;

use Omnitrix\Database\Collection;
use Omnitrix\Database\Model;
use Omnitrix\Database\ModelQuery;

/**
 * Shared behaviour of {@see HasOne} and {@see HasMany}: the related table holds a foreign key
 * pointing at the parent.
 *
 * @template TRelated of Model
 *
 * @extends Relation<TRelated>
 */
abstract class HasOneOrMany extends Relation
{
    /** @param class-string<TRelated> $related */
    public function __construct(Model $parent, string $related, protected readonly string $foreignKey, protected readonly string $localKey)
    {
        parent::__construct($parent, $related);
    }

    /**
     * Creates a related model with the foreign key set to this parent.
     *
     * @param array<string, mixed> $attributes fillable attributes
     *
     * @return TRelated
     */
    public function create(array $attributes): Model
    {
        $class = $this->related;
        $model = new $class($attributes);
        $model->forceFill([$this->foreignKey => $this->parentKey()]);
        $model->save();

        return $model;
    }

    /**
     * Sets the foreign key on an existing model and saves it.
     *
     * @param TRelated $model
     *
     * @return TRelated
     */
    public function save(Model $model): Model
    {
        $model->forceFill([$this->foreignKey => $this->parentKey()]);
        $model->save();

        return $model;
    }

    protected function addConstraints(ModelQuery $query): void
    {
        $query->where($query->qualify($this->foreignKey), $this->parentKey());
    }

    protected function addEagerConstraints(ModelQuery $query, Collection $parents): void
    {
        $query->whereIn($query->qualify($this->foreignKey), self::keys($parents, $this->localKey));
    }

    protected function match(Collection $parents, Collection $results, string $name): void
    {
        $grouped = [];
        foreach ($results as $result) {
            $key = self::keyString($result->getAttributes()[$this->foreignKey] ?? null);
            if ($key !== null) {
                $grouped[$key][] = $result;
            }
        }
        foreach ($parents as $parent) {
            $key = self::keyString($parent->getAttributes()[$this->localKey] ?? null);
            $this->assign($parent, $name, $key === null ? [] : ($grouped[$key] ?? []));
        }
    }

    /** @param list<TRelated> $models */
    abstract protected function assign(Model $parent, string $name, array $models): void;

    protected function parentKey(): mixed
    {
        return $this->parent->getAttributes()[$this->localKey] ?? null;
    }
}
