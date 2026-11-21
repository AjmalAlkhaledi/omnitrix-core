<?php

declare(strict_types=1);

namespace Omnitrix\Auth\Events;

use Omnitrix\Contracts\MustVerifyEmail;

/** Dispatched when a user confirmed their e-mail address. */
final readonly class Verified
{
    public function __construct(public MustVerifyEmail $user) {}
}
