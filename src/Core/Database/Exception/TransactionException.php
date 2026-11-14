<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database\Exception;

/** Transaction control failed or was misused (commit without begin, nested misuse…). */
final class TransactionException extends DatabaseException {}
