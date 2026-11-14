<?php

declare(strict_types=1);

namespace Omnitrix\Core\Http\Server;

use Omnitrix\Core\Runtime\Config\InvalidConfigurationException;

use function array_key_exists;
use function array_keys;
use function implode;
use function in_array;
use function is_float;
use function is_int;
use function is_string;
use function preg_match;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Validated `http` configuration section (transport-level policies of the HTTP adapter).
 */
final readonly class HttpServerConfig
{
    private const array KNOWN_KEYS = ['max_body_size', 'request_timeout', 'json_max_depth', 'request_id_header', 'json_flags'];

    private function __construct(
        /** Maximum accepted request body in bytes (checked before the handler runs → 413). */
        public int $maxBodySize,
        /** Seconds a handler may take before the client receives 503 (0 = unlimited). */
        public float $requestTimeout,
        /** Maximum nesting depth accepted by {@see IncomingRequest::json()}. */
        public int $jsonMaxDepth,
        /** Response header carrying the request id, or null to omit it. */
        public ?string $requestIdHeader,
        /** json_encode flags used by {@see ResponseWriter::json()}. */
        public int $jsonFlags,
    ) {}

    /**
     * @param array<string, mixed> $config the `http` section
     * @param int $packageMaxLength the server's parser limit; max_body_size may not exceed it
     *
     * @throws InvalidConfigurationException
     */
    public static function fromArray(array $config, int $packageMaxLength = 2 * 1024 * 1024): self
    {
        foreach (array_keys($config) as $key) {
            if (!in_array((string) $key, self::KNOWN_KEYS, true)) {
                throw InvalidConfigurationException::at('http.' . $key, 'unknown option; known options are ' . implode(', ', self::KNOWN_KEYS));
            }
        }

        $maxBody = $config['max_body_size'] ?? 1024 * 1024;
        if (!is_int($maxBody) || $maxBody < 1) {
            throw InvalidConfigurationException::at('http.max_body_size', 'expected positive integer (bytes)');
        }
        if ($maxBody > $packageMaxLength) {
            throw InvalidConfigurationException::at('http.max_body_size', "must not exceed server.package_max_length ({$packageMaxLength})");
        }

        $timeout = $config['request_timeout'] ?? 30.0;
        if (is_int($timeout)) {
            $timeout = (float) $timeout;
        }
        if (!is_float($timeout) || $timeout < 0.0 || $timeout > 3600.0) {
            throw InvalidConfigurationException::at('http.request_timeout', 'expected seconds between 0 (disabled) and 3600');
        }

        $depth = $config['json_max_depth'] ?? 64;
        if (!is_int($depth) || $depth < 1 || $depth > 1024) {
            throw InvalidConfigurationException::at('http.json_max_depth', 'expected integer between 1 and 1024');
        }

        $idHeader = array_key_exists('request_id_header', $config) ? $config['request_id_header'] : 'X-Request-Id';
        if ($idHeader !== null && (!is_string($idHeader) || preg_match('/^[A-Za-z0-9-]+$/', $idHeader) !== 1)) {
            throw InvalidConfigurationException::at('http.request_id_header', 'expected a header token or null');
        }

        $flags = $config['json_flags'] ?? JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if (!is_int($flags)) {
            throw InvalidConfigurationException::at('http.json_flags', 'expected integer json_encode flags');
        }

        return new self($maxBody, $timeout, $depth, $idHeader, $flags);
    }

    public static function defaults(): self
    {
        return self::fromArray([]);
    }
}
