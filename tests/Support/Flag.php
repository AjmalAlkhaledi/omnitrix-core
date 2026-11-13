<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Support;

/** Mutable holder for state changed inside closures (static analysis cannot follow by-ref captures). */
final class Flag
{
    public bool $set = false;

    public int $count = 0;

    public mixed $value = null;

    public string $text = '';
}
