<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database\Cache;

use Omnitrix\Core\Database\Exception\DatabaseException;

/** The row cache could not be updated before a write and the connection runs in strict mode. */
final class CacheUnavailableException extends DatabaseException {}
