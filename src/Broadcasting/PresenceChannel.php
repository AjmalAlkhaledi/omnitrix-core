<?php

declare(strict_types=1);

namespace Omnitrix\Broadcasting;

/** A private channel that also tells listeners who else is listening (`presence-chat.1`). */
final class PresenceChannel extends PrivateChannel
{
    public const string PREFIX = 'presence-';
}
