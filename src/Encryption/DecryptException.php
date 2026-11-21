<?php

declare(strict_types=1);

namespace Omnitrix\Encryption;

use RuntimeException;

/** A payload that is not valid, was changed, or was encrypted with an unknown key. */
final class DecryptException extends RuntimeException {}
