<?php

declare(strict_types=1);

namespace Omnitrix\Routing;

/** Result of a successful dispatch. */
final readonly class RouteMatch
{
    /** @param array<string, string> $params */
    public function __construct(public Route $route, public array $params) {}
}
