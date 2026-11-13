<?php

declare(strict_types=1);

namespace Omnitrix\Core\IO;

/** A socket operation did not complete within its timeout. */
final class IoTimeoutException extends IoException {}
