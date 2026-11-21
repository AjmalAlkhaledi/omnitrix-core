<?php

declare(strict_types=1);

namespace Omnitrix\Session;

use Omnitrix\Core\Runtime\Config\InvalidConfigurationException;

use function array_keys;
use function implode;
use function in_array;
use function is_bool;
use function is_int;
use function is_string;

/** Validated `session` configuration section. */
final readonly class SessionConfig
{
    private const array KNOWN = ['driver', 'lifetime', 'cookie', 'path', 'domain', 'secure', 'http_only', 'same_site', 'prefix'];

    private function __construct(
        /** redis, cookie or array */
        public string $driver,
        /** Minutes of inactivity before the session expires. */
        public int $lifetime,
        public string $cookie,
        public string $path,
        public string $domain,
        public bool $secure,
        public bool $httpOnly,
        public string $sameSite,
        public string $prefix,
    ) {}

    /** @param array<string, mixed> $config */
    public static function fromArray(array $config, bool $redisAvailable): self
    {
        foreach (array_keys($config) as $key) {
            if (!in_array((string) $key, self::KNOWN, true)) {
                throw InvalidConfigurationException::at('session.' . $key, 'unknown option; known options are ' . implode(', ', self::KNOWN));
            }
        }
        $driver = $config['driver'] ?? ($redisAvailable ? 'redis' : 'cookie');
        if (!in_array($driver, ['redis', 'cookie', 'array'], true)) {
            throw InvalidConfigurationException::at('session.driver', "expected 'redis', 'cookie' or 'array'");
        }
        if ($driver === 'redis' && !$redisAvailable) {
            throw InvalidConfigurationException::at('session.driver', "'redis' needs the redis configuration section");
        }
        $lifetime = $config['lifetime'] ?? 120;
        if (!is_int($lifetime) || $lifetime < 1) {
            throw InvalidConfigurationException::at('session.lifetime', 'expected minutes (integer >= 1)');
        }
        $sameSite = $config['same_site'] ?? 'Lax';
        if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            throw InvalidConfigurationException::at('session.same_site', "expected 'Lax', 'Strict' or 'None'");
        }
        $string = static function (string $key, string $default) use ($config): string {
            $value = $config[$key] ?? $default;
            if (!is_string($value)) {
                throw InvalidConfigurationException::at("session.{$key}", 'expected a string');
            }

            return $value;
        };
        $bool = static function (string $key, bool $default) use ($config): bool {
            $value = $config[$key] ?? $default;
            if (!is_bool($value)) {
                throw InvalidConfigurationException::at("session.{$key}", 'expected true or false');
            }

            return $value;
        };

        return new self($driver, $lifetime, $string('cookie', 'omnitrix_session'), $string('path', '/'), $string('domain', ''), $bool('secure', $sameSite === 'None'), $bool('http_only', true), $sameSite, $string('prefix', 'session:'));
    }
}
