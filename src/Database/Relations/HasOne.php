<?php

declare(strict_types=1);

namespace Omnitrix\Database\Relations;

use Omnitrix\Database\Model;

/**
 * `users.id` ← `profiles.user_id`: a user has one profile.
 *
 * @template TRelated of Model
 *
 * @extends HasOneOrMany<TRelated>
 */
final class HasOne extends HasOneOrMany
{
    /** @return TRelated|null */
    public function getResults(): ?Model
    {
        return $this->parentKey() === null ? null : $this->query()->first();
    }

    protected function assign(Model $parent, string $name, array $models): void
    {
        $parent->setRelation($name, $models[0] ?? null);
    }
}
