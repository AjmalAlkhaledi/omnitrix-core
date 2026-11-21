<?php

declare(strict_types=1);

namespace Omnitrix\Auth\Access;

/** The outcome of a check, with an optional message (and HTTP status for denials). */
final readonly class AuthorizationResponse
{
    private function __construct(public bool $allowed, public ?string $message = null, public int $status = 403) {}

    public static function allow(?string $message = null): self
    {
        return new self(true, $message);
    }

    public static function deny(?string $message = null, int $status = 403): self
    {
        return new self(false, $message, $status);
    }

    /** A denial answered 404, hiding that the resource exists. */
    public static function denyAsNotFound(?string $message = null): self
    {
        return new self(false, $message, 404);
    }

    public function denied(): bool
    {
        return !$this->allowed;
    }
}
