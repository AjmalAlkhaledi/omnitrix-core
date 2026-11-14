<?php

declare(strict_types=1);

namespace Omnitrix\Core\IO;

use RuntimeException;

/** Base class of socket, DNS and filesystem errors. */
class IoException extends RuntimeException {}
