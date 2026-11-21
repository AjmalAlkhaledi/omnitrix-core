<?php

declare(strict_types=1);

namespace Omnitrix\Events;

/**
 * Marks an event whose listeners must only run once the surrounding database transaction has
 * committed (and never if it rolls back). Outside a transaction it is dispatched at once.
 */
interface ShouldDispatchAfterCommit {}
