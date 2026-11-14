<?php

declare(strict_types=1);

namespace Omnitrix\Core\IO;

/** The peer closed the connection before the expected data arrived. */
final class ConnectionClosedException extends IoException {}
