<?php

declare(strict_types=1);

namespace Omnitrix\Broadcasting;

use InvalidArgumentException;

use function preg_match;

/** A public channel: anyone may listen (`new Channel('orders')`). */
class Channel
{
    public const string PREFIX = '';

    public readonly string $name;

    public function __construct(string $name)
    {
        if (preg_match('/^[A-Za-z0-9_.\-=@,;]+$/', $name) !== 1) {
            throw new InvalidArgumentException("Invalid channel name '{$name}'");
        }
        $this->name = static::PREFIX . $name;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
