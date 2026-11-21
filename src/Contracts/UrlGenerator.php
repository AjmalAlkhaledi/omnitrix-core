<?php

declare(strict_types=1);

namespace Omnitrix\Contracts;

use DateTimeInterface;
use Omnitrix\Http\Request;

/** Builds (signed) URLs. */
interface UrlGenerator
{
    /** @param array<string, scalar|null> $query */
    public function to(string $path, array $query = []): string;

    /** @param array<string, scalar|null> $parameters */
    public function route(string $name, array $parameters = []): string;

    /** @param array<string, scalar|null> $parameters */
    public function signedRoute(string $name, array $parameters = [], int|DateTimeInterface|null $expiration = null): string;

    public function hasValidSignature(Request $request): bool;
}
