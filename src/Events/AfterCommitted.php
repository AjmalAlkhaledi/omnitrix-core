<?php

declare(strict_types=1);

namespace Omnitrix\Events;

/** @internal wraps an after-commit event once its transaction committed */
final readonly class AfterCommitted
{
    public function __construct(public object $event) {}
}
