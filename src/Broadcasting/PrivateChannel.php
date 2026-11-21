<?php

declare(strict_types=1);

namespace Omnitrix\Broadcasting;

/** A channel only authorized users may listen to (`new PrivateChannel('user.5')` → `private-user.5`). */
class PrivateChannel extends Channel
{
    public const string PREFIX = 'private-';
}
