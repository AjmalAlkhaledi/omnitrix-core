<?php

declare(strict_types=1);

namespace Omnitrix\Core\Concurrency;

use RuntimeException;

/** A deadline passed before the awaited work completed. */
final class TimeoutException extends RuntimeException {}
