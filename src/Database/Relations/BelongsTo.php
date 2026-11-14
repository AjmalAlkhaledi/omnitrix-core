<?php

declare(strict_types=1);

namespace Omnitrix\Database\Relations;

use Omnitrix\Database\Collection;
use Omnitrix\Database\Model;
use Omnitrix\Database\ModelQuery;

/**
 * `posts.user_id` → `users.id`: a post belongs to its author.
 *
 * @template TRelated of Model
 *
 * @extends Relation<TRelated>
 */
final class BelongsTo extends Relation
{
    /** @param class-string<TRelated> $related */
    public function __construct(Model $child, string $related, private readonly string $foreignKey, private readonly string $ownerKey)
    {
        parent::__construct($child, $related);
    }

    /** @return TRelated|null */
    public function getResults(): ?Model
    {
        return $this->foreignValue() === null ? null : $this->query()->first();
    }

    /**
     * Points the child at `$owner` (or nowhere) without saving.
     *
     * @param TRelated|null $owner
     */
    public function associate(?Model $owner): Model
    {
        $this->parent->forceFill([$this->foreignKey => $owner?->getAttributes()[$this->ownerKey] ?? null]);

        return $this->parent;
    }

    public function dissociate(): Model
    {
        return $this->associate(null);
    }

    protected function addConstraints(ModelQuery $query): void
    {
        $query->where($query->qualify($this->ownerKey), $this->foreignValue());
    }

    protected function addEagerConstraints(ModelQuery $query, Collection $parents): void
    {
        $query->whereIn($query->qualify($this->ownerKey), self::keys($parents, $this->foreignKey));
    }

    protected function match(Collection $parents, Collection $results, string $name): void
    {
        $owners = [];
        foreach ($results as $result) {
            $key = self::keyString($result->getAttributes()[$this->ownerKey] ?? null);
            if ($key !== null) {
                $owners[$key] = $result;
            }
        }
        foreach ($parents as $parent) {
            $key = self::keyString($parent->getAttributes()[$this->foreignKey] ?? null);
            $parent->setRelation($name, $key === null ? null : ($owners[$key] ?? null));
        }
    }

    private function foreignValue(): mixed
    {
        return $this->parent->getAttributes()[$this->foreignKey] ?? null;
    }
}
