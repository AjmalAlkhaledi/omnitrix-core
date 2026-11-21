<?php

declare(strict_types=1);

namespace Omnitrix\Facades;

use DateTimeInterface;
use Omnitrix\Http\Request;
use Omnitrix\Routing\UrlGenerator;

/**
 * URLs and signed URLs (documentation: The basics → Routing).
 *
 * ```php
 * URL::route('users.show', ['id' => 10]);
 * URL::signedRoute('unsubscribe', ['user' => 10], 86400);
 * ```
 */
final class URL extends Facade
{
    public static function generator(): UrlGenerator
    {
        return self::resolve(UrlGenerator::class);
    }

    /** @param array<string, scalar|null> $query */
    public static function to(string $path, array $query = []): string
    {
        return self::generator()->to($path, $query);
    }

    /** @param array<string, scalar|null> $parameters */
    public static function route(string $name, array $parameters = []): string
    {
        return self::generator()->route($name, $parameters);
    }

    /** @param array<string, scalar|null> $parameters */
    public static function signedRoute(string $name, array $parameters = [], int|DateTimeInterface|null $expiration = null): string
    {
        return self::generator()->signedRoute($name, $parameters, $expiration);
    }

    /** @param array<string, scalar|null> $parameters */
    public static function temporarySignedRoute(string $name, int|DateTimeInterface $expiration, array $parameters = []): string
    {
        return self::generator()->temporarySignedRoute($name, $expiration, $parameters);
    }

    public static function hasValidSignature(Request $request): bool
    {
        return self::generator()->hasValidSignature($request);
    }
}
