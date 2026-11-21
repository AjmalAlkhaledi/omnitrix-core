<?php

declare(strict_types=1);

namespace Omnitrix\Auth\Events;

use Omnitrix\Contracts\Authenticatable;

/** Dispatched after a password was reset through the password broker. */
final readonly class PasswordReset
{
    public function __construct(public Authenticatable $user) {}
}
