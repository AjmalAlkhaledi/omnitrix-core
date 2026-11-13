<?php

declare(strict_types=1);

namespace Omnitrix\Core\Redis\Exception;

/** The server rejected a command (WRONGTYPE, syntax, NOAUTH…); the connection stays usable. */
final class CommandException extends RedisException {}
