<?php

declare(strict_types=1);

namespace Omnitrix\Contracts;

/** A user the authentication guards can log in. */
interface Authenticatable
{
    public function getAuthIdentifierName(): string;

    public function getAuthIdentifier(): int|string|null;

    /** The stored password hash. */
    public function getAuthPassword(): string;

    public function getRememberToken(): ?string;

    public function setRememberToken(?string $token): void;
}
