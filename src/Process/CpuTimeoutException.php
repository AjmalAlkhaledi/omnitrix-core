<?php

declare(strict_types=1);

namespace Omnitrix\Core\Process;

/** The job did not finish in time; if it had started, its worker process was killed and is restarted. */
final class CpuTimeoutException extends CpuException {}
