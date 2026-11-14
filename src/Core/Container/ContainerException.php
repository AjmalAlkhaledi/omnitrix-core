<?php

declare(strict_types=1);

namespace Omnitrix\Core\Container;

use Psr\Container\ContainerExceptionInterface;
use RuntimeException;

final class ContainerException extends RuntimeException implements ContainerExceptionInterface {}
