<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database\Exception;

/** Deadlock detected; the transaction was rolled back and may be retried. */
class DeadlockException extends QueryException {}
