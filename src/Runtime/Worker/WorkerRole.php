<?php

declare(strict_types=1);

namespace Omnitrix\Core\Runtime\Worker;

enum WorkerRole: string
{
    case Http = 'http';
    case Task = 'task';
}
