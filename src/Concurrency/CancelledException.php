<?php

declare(strict_types=1);

namespace Omnitrix\Core\Concurrency;

use RuntimeException;

/** The operation was cancelled through its {@see CancellationToken}. */
final class CancelledException extends RuntimeException {}
