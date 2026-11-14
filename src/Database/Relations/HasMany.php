<?php

declare(strict_types=1);

namespace Omnitrix\Database\Relations;

use Omnitrix\Database\Collection;
use Omnitrix\Database\Model;

/**
 * `users.id` ← `posts.user_id`: a user has many posts.
 *
 * @template TRelated of Model
 *
 * @extends HasOneOrMany<TRelated>
 */
final class HasMany extends HasOneOrMany
{
    /** @return Collection<TRelated> */
    public function getResults(): Collection
    {
        return $this->parentKey() === null ? new Collection() : $this->query()->get();
    }

    protected function assign(Model $parent, string $name, array $models): void
    {
        $parent->setRelation($name, new Collection($models));
    }
}
