<?php

declare(strict_types=1);

namespace Omnitrix\Core\Redis;

use Omnitrix\Core\Redis\Exception\CommandException;
use Omnitrix\Core\Redis\Exception\ConnectionException;
use Omnitrix\Core\Redis\Exception\RedisException;
use Omnitrix\Core\Redis\Exception\TimeoutException;
use Throwable;

use function str_contains;
use function strtolower;

/** Maps phpredis exceptions (message-based; phpredis has no error codes) to typed exceptions. */
final class ErrorClassifier
{
    private function __construct() {}

    public static function classify(Throwable $e, string $context = ''): RedisException
    {
        $message = $e->getMessage() . ($context !== '' ? " ({$context})" : '');
        $lower = strtolower($e->getMessage());
        if (str_contains($lower, 'read error on connection') || str_contains($lower, 'timed out') || str_contains($lower, 'timeout')) {
            return new TimeoutException($message, 0, $e);
        }
        foreach (['connection refused', 'connection lost', 'went away', 'connection closed', 'socket error', 'broken pipe', 'connection reset', 'redis server went away', 'no connection', 'php_network_getaddresses', 'noauth', 'wrongpass', 'auth failed', 'not connected'] as $needle) {
            if (str_contains($lower, $needle)) {
                return new ConnectionException($message, 0, $e);
            }
        }

        return new CommandException($message, 0, $e);
    }
}
