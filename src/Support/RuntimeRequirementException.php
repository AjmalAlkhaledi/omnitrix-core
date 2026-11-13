<?php

declare(strict_types=1);

namespace Omnitrix\Core\Support;

use RuntimeException;

/**
 * Thrown when the process does not satisfy a hard runtime requirement of the framework
 * (missing extension, unsupported version, unavailable kernel feature that was requested).
 */
final class RuntimeRequirementException extends RuntimeException {}
