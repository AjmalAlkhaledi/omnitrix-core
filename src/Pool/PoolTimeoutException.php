<?php

declare(strict_types=1);

namespace Omnitrix\Core\Pool;

use RuntimeException;

/** No pooled client became available within the acquisition timeout (backpressure signal). */
final class PoolTimeoutException extends RuntimeException {}
