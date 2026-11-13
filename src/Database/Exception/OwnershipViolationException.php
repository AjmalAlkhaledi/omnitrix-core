<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database\Exception;

use LogicException;

/**
 * A pooled connection was used from a coroutine that does not own it, or after release.
 * Sharing one connection between coroutines corrupts the protocol stream (OpenSwoole aborts
 * the worker with "socket bound to another coroutine"), so this is a programming error.
 */
final class OwnershipViolationException extends LogicException {}
