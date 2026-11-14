<?php

declare(strict_types=1);

namespace Omnitrix\Core\Log;

use DateTimeImmutable;
use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;
use Stringable;
use Throwable;

use function array_search;
use function fwrite;
use function get_debug_type;
use function is_array;
use function is_int;
use function is_resource;
use function is_scalar;
use function json_encode;
use function strtolower;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_PARTIAL_OUTPUT_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const PHP_EOL;
use const STDERR;

/**
 * Minimal structured (one JSON object per line) PSR-3 logger writing to a stream.
 *
 * Redaction: context keys listed in `$redactKeys` (case-insensitive) are replaced by "[redacted]"
 * so credentials never reach the log. Throwables in context are serialised as class/message/
 * file/line without their trace unless `$includeTraces` is enabled. Records below
 * `$minimumLevel` (default: debug, i.e. everything) are discarded.
 */
final class JsonLogger extends AbstractLogger
{
    private const array LEVELS = [
        LogLevel::EMERGENCY, LogLevel::ALERT, LogLevel::CRITICAL, LogLevel::ERROR,
        LogLevel::WARNING, LogLevel::NOTICE, LogLevel::INFO, LogLevel::DEBUG,
    ];

    /** @var resource */
    private $stream;

    /** @var array<string, true> */
    private array $redact = [];

    private readonly int $threshold;

    /**
     * @param resource|null $stream       defaults to STDERR
     * @param list<string>  $redactKeys
     * @param string        $minimumLevel a PSR-3 level; less severe records are discarded
     */
    public function __construct($stream = null, array $redactKeys = ['password', 'secret', 'token', 'authorization', 'api_key', 'apikey'], private readonly bool $includeTraces = false, private readonly string $channel = 'omnitrix', string $minimumLevel = LogLevel::DEBUG)
    {
        $threshold = array_search($minimumLevel, self::LEVELS, true);
        if (!is_int($threshold)) {
            throw new InvalidArgumentException("Unknown log level '{$minimumLevel}'");
        }
        $this->threshold = $threshold;
        $this->stream = is_resource($stream) ? $stream : STDERR;
        foreach ($redactKeys as $key) {
            $this->redact[strtolower($key)] = true;
        }
    }

    /**
     * @param mixed        $level
     * @param array<mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $severity = array_search($level, self::LEVELS, true);
        if (!is_int($severity)) {
            throw new InvalidArgumentException('Unknown log level ' . get_debug_type($level));
        }
        if ($severity > $this->threshold) {
            return;
        }

        $record = [
            'ts' => (new DateTimeImmutable())->format('Y-m-d\TH:i:s.uP'),
            'level' => $level,
            'channel' => $this->channel,
            'message' => (string) $message,
        ];

        foreach ($context as $key => $value) {
            $record[$key] = $this->normalise((string) $key, $value);
        }

        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        fwrite($this->stream, ($line === false ? '{"level":"error","message":"log encoding failed"}' : $line) . PHP_EOL);
    }

    private function normalise(string $key, mixed $value): mixed
    {
        if (isset($this->redact[strtolower($key)])) {
            return '[redacted]';
        }
        if ($value instanceof Throwable) {
            $e = ['class' => $value::class, 'message' => $value->getMessage(), 'code' => $value->getCode(), 'file' => $value->getFile(), 'line' => $value->getLine()];
            if ($this->includeTraces) {
                $e['trace'] = $value->getTraceAsString();
            }
            if ($value->getPrevious() !== null) {
                $e['previous'] = $this->normalise('previous', $value->getPrevious());
            }

            return $e;
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->normalise((string) $k, $v);
            }

            return $out;
        }
        if ($value === null || is_scalar($value) || $value instanceof Stringable) {
            return $value instanceof Stringable ? (string) $value : $value;
        }

        return get_debug_type($value);
    }
}
