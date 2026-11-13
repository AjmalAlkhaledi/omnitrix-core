<?php

declare(strict_types=1);

namespace Omnitrix\Core\Redis\Exception;

/** Connect/auth failure, lost connection or read timeout; the pooled connection is discarded. */
class ConnectionException extends RedisException {}
