<?php

declare(strict_types=1);

namespace Omnitrix\Core\Container;

use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

final class NotFoundException extends RuntimeException implements NotFoundExceptionInterface {}
