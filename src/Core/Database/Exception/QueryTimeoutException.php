<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database\Exception;

/** Statement exceeded its time limit. */
class QueryTimeoutException extends QueryException {}
