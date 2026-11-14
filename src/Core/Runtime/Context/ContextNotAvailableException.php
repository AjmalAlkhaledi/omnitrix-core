<?php

declare(strict_types=1);

namespace Omnitrix\Core\Runtime\Context;

use LogicException;

/** Raised when request-scoped state is accessed outside of a request coroutine (or its children). */
final class ContextNotAvailableException extends LogicException {}
