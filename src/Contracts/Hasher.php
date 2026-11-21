<?php

declare(strict_types=1);

namespace Omnitrix\Contracts;

/** One-way password hashing. */
interface Hasher
{
    public function make(string $value): string;

    public function check(string $value, string $hash): bool;

    /** Whether `$hash` was made with other settings than the current ones (re-hash after login). */
    public function needsRehash(string $hash): bool;
}
