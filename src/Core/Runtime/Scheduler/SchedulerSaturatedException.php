<?php

declare(strict_types=1);

namespace Omnitrix\Core\Runtime\Scheduler;

use RuntimeException;

/** The bounded scheduler could not admit a task within the acquisition timeout. */
final class SchedulerSaturatedException extends RuntimeException {}
