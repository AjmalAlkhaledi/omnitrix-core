<?php

declare(strict_types=1);

namespace Omnitrix\Core\Concurrency;

enum TaskState: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
