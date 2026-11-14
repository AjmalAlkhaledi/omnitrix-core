<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database\Exception;

/**
 * The connection could not be established or was lost. The pool discards the connection and
 * the operation may be retried on a fresh one if it is idempotent.
 */
class ConnectionException extends DatabaseException {}
