<?php

declare(strict_types=1);

namespace Omnitrix\Core\Support;

use function bin2hex;
use function random_bytes;

/**
 * Opaque identifiers (request ids, task ids). Cryptographically random, URL-safe hex.
 */
final class Id
{
    private function __construct() {}

    /** 32 hex characters (128 bits). */
    public static function generate(): string
    {
        return bin2hex(random_bytes(16));
    }
}
