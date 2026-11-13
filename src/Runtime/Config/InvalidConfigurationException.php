<?php

declare(strict_types=1);

namespace Omnitrix\Core\Runtime\Config;

use InvalidArgumentException;

/**
 * A configuration value is missing, has the wrong type or is out of range.
 * The message always names the dotted configuration path.
 */
final class InvalidConfigurationException extends InvalidArgumentException
{
    public static function at(string $path, string $problem): self
    {
        return new self("Invalid configuration at '{$path}': {$problem}");
    }
}
