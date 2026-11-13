<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database\Exception;

/** No connection became available within the acquisition timeout (backpressure signal). */
final class PoolExhaustedException extends DatabaseException {}
