<?php

declare(strict_types=1);

namespace Omnitrix\Core\Redis\Exception;

/** A read timed out (blocking command or slow server); the connection is discarded. */
final class TimeoutException extends ConnectionException {}
