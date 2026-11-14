<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database\Exception;

/** Duplicate entry violates a unique constraint. */
class UniqueConstraintViolationException extends ConstraintViolationException {}
