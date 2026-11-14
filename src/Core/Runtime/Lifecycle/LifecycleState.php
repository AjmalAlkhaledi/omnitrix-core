<?php

declare(strict_types=1);

namespace Omnitrix\Core\Runtime\Lifecycle;

enum LifecycleState: string
{
    case Created = 'created';
    case Built = 'built';
    case Running = 'running';
    case Stopped = 'stopped';
}
