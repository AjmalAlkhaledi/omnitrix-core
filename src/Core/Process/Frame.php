<?php

declare(strict_types=1);

namespace Omnitrix\Core\Process;

use JsonException;

use function is_array;
use function is_int;
use function json_decode;
use function json_encode;
use function pack;
use function strlen;
use function unpack;

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Wire format between HTTP workers and CPU workers: 4-byte big-endian length + JSON object.
 * JSON (not serialize()) keeps the boundary free of object injection.
 *
 * @internal
 */
final class Frame
{
    public const int MAX_SIZE = 64 * 1024 * 1024;

    private function __construct() {}

    /** @param array<string, mixed> $message */
    public static function encode(array $message): string
    {
        $json = json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if (strlen($json) > self::MAX_SIZE) {
            throw new CpuException('Frame exceeds ' . self::MAX_SIZE . ' bytes');
        }

        return pack('N', strlen($json)) . $json;
    }

    /** Length from a 4-byte header. */
    public static function length(string $header): int
    {
        $unpacked = unpack('N', $header);
        $length = is_array($unpacked) ? ($unpacked[1] ?? -1) : -1;
        if (!is_int($length) || $length < 0 || $length > self::MAX_SIZE) {
            throw new CpuException('Invalid frame length');
        }

        return $length;
    }

    /** @return array<string, mixed> */
    public static function decode(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new CpuException('Malformed frame: ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($decoded)) {
            throw new CpuException('Frame is not an object');
        }
        $out = [];
        foreach ($decoded as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }
}
