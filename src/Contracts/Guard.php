<?php

declare(strict_types=1);

namespace Omnitrix\Contracts;

/** Authenticates the current request. */
interface Guard
{
    public function user(): ?Authenticatable;

    public function id(): int|string|null;

    public function check(): bool;

    public function guest(): bool;

    /** @param array<string, mixed> $credentials */
    public function validate(array $credentials): bool;

    public function setUser(?Authenticatable $user): void;
}
