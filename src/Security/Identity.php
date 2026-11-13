<?php

declare(strict_types=1);

namespace Omnitrix\Security;

use function in_array;

/** The authenticated principal attached to a request by {@see BearerTokenAuth}. */
final readonly class Identity
{
    /**
     * @param list<string>          $abilities
     * @param array<string, mixed>  $claims
     */
    public function __construct(public string $id, public array $abilities = [], public array $claims = []) {}

    public function can(string $ability): bool
    {
        return in_array('*', $this->abilities, true) || in_array($ability, $this->abilities, true);
    }
}
