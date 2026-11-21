<?php

declare(strict_types=1);

namespace Omnitrix\Contracts;

use Omnitrix\Auth\Access\AuthorizationResponse;

/** Decides whether the current user may perform an ability. */
interface Gate
{
    public function allows(string $ability, mixed ...$arguments): bool;

    public function denies(string $ability, mixed ...$arguments): bool;

    public function inspect(string $ability, mixed ...$arguments): AuthorizationResponse;

    /** @throws \Omnitrix\Auth\Access\AuthorizationException */
    public function authorize(string $ability, mixed ...$arguments): AuthorizationResponse;

    public function forUser(?Authenticatable $user): self;
}
