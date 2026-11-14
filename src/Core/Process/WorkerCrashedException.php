<?php

declare(strict_types=1);

namespace Omnitrix\Core\Process;

/** The worker process died while running the job (fatal error, OOM, signal). It is restarted. */
final class WorkerCrashedException extends CpuException {}
