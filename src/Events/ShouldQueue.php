<?php

declare(strict_types=1);

namespace Omnitrix\Events;

/**
 * Marks a listener class to run in the queue worker (`php omnitrix queue:work`) instead of
 * during the request. The event is serialized with the job: keep events small and
 * serializable (no open connections or closures). Optional public properties: `$queue`
 * (queue name) and `$delay` (seconds).
 */
interface ShouldQueue {}
