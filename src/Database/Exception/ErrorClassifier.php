<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database\Exception;

use PDOException;

use function count;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function str_contains;
use function strtolower;

/**
 * Maps PDO/MySQL errors to the framework exception hierarchy so callers (and the pool) can
 * react without parsing messages: connection errors discard the connection, deadlocks may be
 * retried, constraint violations map to 409/422, timeouts to 504.
 */
final class ErrorClassifier
{
    /** MySQL client/server error numbers meaning the connection is unusable. */
    private const array CONNECTION_CODES = [2002, 2003, 2006, 2013, 2055, 1040, 1042, 1043, 1044, 1045, 1047, 1053, 1077, 1079, 1080, 1081, 1129, 1130, 1152, 1153, 1158, 1159, 1160, 1161, 1184, 1927, 4031];

    private function __construct() {}

    /** @param array<mixed> $bindings */
    public static function classify(PDOException $e, string $sql = '', array $bindings = []): DatabaseException
    {
        [$sqlState, $driverCode, $driverMessage] = self::errorInfo($e);
        $message = $driverMessage !== '' ? $driverMessage : $e->getMessage();
        $described = $sql === '' ? $message : $message . ' — ' . QueryException::describe($sql, $bindings);

        if (self::isConnectionError($sqlState, $driverCode, $message)) {
            return new ConnectionException($message, $driverCode, $e);
        }

        return match (true) {
            $driverCode === 1213 => new DeadlockException($described, $sql, $sqlState, $driverCode, count($bindings), $e),
            $driverCode === 1205 => new LockWaitTimeoutException($described, $sql, $sqlState, $driverCode, count($bindings), $e),
            in_array($driverCode, [1969, 3024, 1317], true) => new QueryTimeoutException($described, $sql, $sqlState, $driverCode, count($bindings), $e),
            $driverCode === 1062 || $driverCode === 1586 => new UniqueConstraintViolationException($described, $sql, $sqlState, $driverCode, count($bindings), $e),
            $sqlState === '23000' || in_array($driverCode, [1048, 1216, 1217, 1451, 1452, 3819, 4025], true) => new ConstraintViolationException($described, $sql, $sqlState, $driverCode, count($bindings), $e),
            default => new QueryException($described, $sql, $sqlState, $driverCode, count($bindings), $e),
        };
    }

    public static function isConnectionError(?string $sqlState, int $driverCode, string $message): bool
    {
        if (in_array($driverCode, self::CONNECTION_CODES, true)) {
            return true;
        }
        if ($sqlState !== null && in_array($sqlState, ['08001', '08004', '08006', '08007', '08S01', 'HY093'], true)) {
            return true;
        }
        $lower = strtolower($message);
        foreach (['server has gone away', 'lost connection', 'connection refused', 'connection timed out', 'broken pipe', 'connection reset', 'packets out of order', 'no connection to the server', 'ssl connection error', 'is not allowed to connect', 'too many connections'] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{string|null, int, string} */
    private static function errorInfo(PDOException $e): array
    {
        $info = $e->errorInfo;
        $sqlState = is_array($info) && isset($info[0]) && is_string($info[0]) ? $info[0] : (is_string($e->getCode()) ? $e->getCode() : null);
        $code = is_array($info) && isset($info[1]) && is_int($info[1]) ? $info[1] : (is_int($e->getCode()) ? $e->getCode() : 0);
        $message = is_array($info) && isset($info[2]) && is_string($info[2]) ? $info[2] : '';

        return [$sqlState, $code, $message];
    }
}
