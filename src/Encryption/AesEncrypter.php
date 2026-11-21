<?php

declare(strict_types=1);

namespace Omnitrix\Encryption;

use InvalidArgumentException;
use JsonException;
use Omnitrix\Contracts\Encrypter;
use Omnitrix\Core\Runtime\Config\InvalidConfigurationException;
use RuntimeException;

use function base64_decode;
use function base64_encode;
use function bin2hex;
use function hash;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function openssl_decrypt;
use function openssl_encrypt;
use function random_bytes;
use function rtrim;
use function str_starts_with;
use function strlen;
use function strpos;
use function strtr;
use function substr;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const OPENSSL_RAW_DATA;

/**
 * AES-256-GCM with the application key (`app.key`, 32 bytes, usually `base64:…` from
 * `php omnitrix key:generate`). Payloads are URL-safe strings carrying the IV, the
 * authentication tag and the id of the key that encrypted them: any change is rejected, and
 * keys listed in `app.previous_keys` still decrypt older payloads after a key rotation.
 */
final class AesEncrypter implements Encrypter
{
    private const string CIPHER = 'aes-256-gcm';

    /** @var array<string, string> key id => raw key (current first) */
    private readonly array $keys;

    private readonly string $currentId;

    /** @param list<string> $previousKeys */
    public function __construct(string $key, array $previousKeys = [])
    {
        $keys = [];
        foreach ([$key, ...$previousKeys] as $configured) {
            $raw = self::parseKey($configured);
            $keys[self::keyId($raw)] = $raw;
        }
        $this->keys = $keys;
        $this->currentId = self::keyId(self::parseKey($key));
    }

    /** @param array<string, mixed> $app the `app` section */
    public static function fromConfig(array $app): ?self
    {
        $key = $app['key'] ?? null;
        if ($key === null || $key === '') {
            return null;
        }
        $previous = $app['previous_keys'] ?? [];
        if (!is_string($key) || !is_array($previous)) {
            throw InvalidConfigurationException::at('app.key', 'expected a key string and a list of previous keys');
        }
        $old = [];
        foreach ($previous as $p) {
            if (is_string($p) && $p !== '') {
                $old[] = $p;
            }
        }
        try {
            return new self($key, $old);
        } catch (InvalidArgumentException $e) {
            throw InvalidConfigurationException::at('app.key', $e->getMessage());
        }
    }

    /** A new random key in the `base64:` form `app.key` expects. */
    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    public function encrypt(mixed $value): string
    {
        return $this->encryptString(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function decrypt(string $payload): mixed
    {
        try {
            return json_decode($this->decryptString($payload), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DecryptException('The payload is not valid');
        }
    }

    public function encryptString(string $value): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($value, self::CIPHER, $this->keys[$this->currentId], OPENSSL_RAW_DATA, $iv, $tag, $this->currentId, 16);
        if (!is_string($cipher)) {
            throw new RuntimeException('Encryption failed');
        }

        return $this->currentId . '.' . self::b64($iv . $tag . $cipher);
    }

    public function decryptString(string $payload): string
    {
        $dot = strpos($payload, '.');
        if ($dot === false) {
            throw new DecryptException('The payload is not valid');
        }
        $id = substr($payload, 0, $dot);
        $key = $this->keys[$id] ?? throw new DecryptException('The payload was encrypted with an unknown key');
        $raw = self::unb64(substr($payload, $dot + 1));
        if ($raw === null || strlen($raw) < 28) {
            throw new DecryptException('The payload is not valid');
        }
        $plain = openssl_decrypt(substr($raw, 28), self::CIPHER, $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), $id);
        if (!is_string($plain)) {
            throw new DecryptException('The payload was changed or the key is wrong');
        }

        return $plain;
    }

    private static function parseKey(string $key): string
    {
        $raw = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        if (!is_string($raw) || strlen($raw) !== 32) {
            throw new InvalidArgumentException('the key must be 32 bytes (generate one with `php omnitrix key:generate`)');
        }

        return $raw;
    }

    private static function keyId(string $raw): string
    {
        return substr(bin2hex(hash('sha256', $raw, true)), 0, 8);
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function unb64(string $text): ?string
    {
        $decoded = base64_decode(strtr($text, '-_', '+/'), true);

        return is_string($decoded) ? $decoded : null;
    }
}
