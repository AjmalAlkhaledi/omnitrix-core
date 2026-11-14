<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database\Exception;

/** Lock wait timeout exceeded. */
class LockWaitTimeoutException extends QueryException {}
