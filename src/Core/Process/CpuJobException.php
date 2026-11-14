<?php

declare(strict_types=1);

namespace Omnitrix\Core\Process;

/** The job threw inside the worker process; the worker survives. Carries the remote class name. */
final class CpuJobException extends CpuException
{
    public function __construct(string $message, public readonly string $remoteClass)
    {
        parent::__construct($message);
    }
}
