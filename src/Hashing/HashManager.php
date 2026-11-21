<?php

declare(strict_types=1);

namespace Omnitrix\Hashing;

use Omnitrix\Contracts\Hasher;
use Omnitrix\Core\Runtime\Config\InvalidConfigurationException;

use function array_keys;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function password_get_info;
use function password_hash;
use function password_needs_rehash;
use function password_verify;

use const PASSWORD_ARGON2ID;
use const PASSWORD_BCRYPT;

/**
 * Password hashing with bcrypt (default) or argon2id, configured by the `hashing` section:
 *
 * ```php
 * 'hashing' => ['driver' => 'bcrypt', 'bcrypt' => ['rounds' => 12], 'argon' => ['memory' => 65536, 'time' => 4, 'threads' => 1]],
 * ```
 *
 * `check()` accepts hashes of either algorithm, so switching drivers keeps existing passwords
 * valid; `needsRehash()` tells when to store a new hash after a successful login. Hashing is
 * CPU work that holds the worker for its duration (about 50–250 ms at the defaults).
 */
final class HashManager implements Hasher
{
    private const array KNOWN = ['driver', 'bcrypt', 'argon'];

    /** @param array<string, int> $options */
    private function __construct(private readonly string $algorithm, private readonly array $options) {}

    /** @param array<string, mixed> $config the `hashing` section */
    public static function fromConfig(array $config): self
    {
        foreach (array_keys($config) as $key) {
            if (!in_array((string) $key, self::KNOWN, true)) {
                throw InvalidConfigurationException::at('hashing.' . $key, 'unknown option; known options are ' . implode(', ', self::KNOWN));
            }
        }
        $driver = $config['driver'] ?? 'bcrypt';
        if ($driver === 'bcrypt') {
            $rounds = self::option($config, 'bcrypt', 'rounds', 12, 4, 31);

            return new self(PASSWORD_BCRYPT, ['cost' => $rounds]);
        }
        if ($driver === 'argon2id' || $driver === 'argon') {
            return new self(PASSWORD_ARGON2ID, [
                'memory_cost' => self::option($config, 'argon', 'memory', 65536, 8, 4194304),
                'time_cost' => self::option($config, 'argon', 'time', 4, 1, 100),
                'threads' => self::option($config, 'argon', 'threads', 1, 1, 64),
            ]);
        }
        throw InvalidConfigurationException::at('hashing.driver', "unsupported driver '" . (is_string($driver) ? $driver : '?') . "' (bcrypt, argon2id)");
    }

    public function make(string $value): string
    {
        return password_hash($value, $this->algorithm, $this->options);
    }

    public function check(string $value, string $hash): bool
    {
        return $hash !== '' && password_verify($value, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algorithm, $this->options);
    }

    /** @return array{algo: string, options: array<string, mixed>} */
    public function info(string $hash): array
    {
        $info = password_get_info($hash);

        return ['algo' => $info['algoName'], 'options' => $info['options']];
    }

    /** @param array<string, mixed> $config */
    private static function option(array $config, string $section, string $key, int $default, int $min, int $max): int
    {
        $values = $config[$section] ?? [];
        $value = is_array($values) ? ($values[$key] ?? $default) : $default;
        if (!is_int($value) || $value < $min || $value > $max) {
            throw InvalidConfigurationException::at("hashing.{$section}.{$key}", "expected integer between {$min} and {$max}");
        }

        return $value;
    }
}
