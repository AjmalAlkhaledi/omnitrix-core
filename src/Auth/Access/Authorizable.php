<?php

declare(strict_types=1);

namespace Omnitrix\Auth\Access;

use Omnitrix\Contracts\Authenticatable;
use Omnitrix\Facades\Gate;

/** `$user->can('update', $post)` / `$user->cannot(...)` for user models. */
trait Authorizable
{
    public function can(string $ability, mixed ...$arguments): bool
    {
        return $this instanceof Authenticatable && Gate::forUser($this)->allows($ability, ...$arguments);
    }

    public function cannot(string $ability, mixed ...$arguments): bool
    {
        return !$this->can($ability, ...$arguments);
    }
}
