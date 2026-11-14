<?php

declare(strict_types=1);

namespace Omnitrix\Core\Runtime\Config;

use Omnitrix\Core\Support\Platform;
use OpenSwoole\Constant;
use OpenSwoole\Runtime;
use OpenSwoole\Util;

use function array_key_exists;
use function array_keys;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function sprintf;
use function strtolower;

/**
 * Validated, immutable runtime/server configuration.
 *
 * Built from the `server` section of the application configuration array. Every value is
 * type- and range-checked here so the rest of the runtime never re-validates, and
 * {@see toOpenSwooleSettings()} is the single place that maps to OpenSwoole option names.
 */
final readonly class RuntimeConfig
{
    /** @var array<string, int> */
    private const array LOG_LEVELS = [
        'debug' => Constant::LOG_DEBUG,
        'trace' => Constant::LOG_TRACE,
        'info' => Constant::LOG_INFO,
        'notice' => Constant::LOG_NOTICE,
        'warning' => Constant::LOG_WARNING,
        'error' => Constant::LOG_ERROR,
        'none' => Constant::LOG_NONE,
    ];

    private const array KNOWN_KEYS = [
        'host', 'port', 'mode', 'workers', 'task_workers', 'max_coroutine', 'max_request',
        'max_wait_time', 'backlog', 'reactor', 'hook_flags', 'log_file', 'log_level',
        'pid_file', 'enable_reuse_port', 'reload_async', 'package_max_length',
        'buffer_output_size', 'tcp_nodelay', 'http2', 'daemonize', 'options',
    ];

    /**
     * Ranges are enforced by {@see fromArray()} (workers >= 1, task workers >= 0, package
     * length >= 64 KiB, …); the constructor is private so no unchecked value can enter.
     *
     * @param array<string, scalar> $extraOptions raw OpenSwoole options passed through verbatim
     */
    private function __construct(
        public string $host,
        public int $port,
        public ServerMode $mode,
        public int $workers,
        public int $taskWorkers,
        public int $maxCoroutine,
        public int $maxRequest,
        public int $maxWaitTime,
        public int $backlog,
        public string $reactor,
        public int $hookFlags,
        public ?string $logFile,
        public int $logLevel,
        public ?string $pidFile,
        public bool $enableReusePort,
        public bool $reloadAsync,
        public int $packageMaxLength,
        public int $bufferOutputSize,
        public bool $tcpNodelay,
        public bool $http2,
        public bool $daemonize,
        public array $extraOptions,
    ) {}

    /**
     * @param array<string, mixed> $config the `server` section
     *
     * @throws InvalidConfigurationException
     */
    public static function fromArray(array $config): self
    {
        foreach (array_keys($config) as $key) {
            if (!in_array((string) $key, self::KNOWN_KEYS, true)) {
                throw InvalidConfigurationException::at('server.' . $key, 'unknown option; known options are ' . implode(', ', self::KNOWN_KEYS));
            }
        }

        $mode = self::string($config, 'mode', 'process');
        $serverMode = ServerMode::tryFrom(strtolower($mode))
            ?? throw InvalidConfigurationException::at('server.mode', "expected 'simple' or 'process', got '{$mode}'");

        $reactor = strtolower(self::string($config, 'reactor', 'epoll'));
        if (!in_array($reactor, ['epoll', 'io_uring'], true)) {
            throw InvalidConfigurationException::at('server.reactor', "expected 'epoll' or 'io_uring', got '{$reactor}'");
        }
        if ($reactor === 'io_uring' && !Platform::supportsIoUring()) {
            throw InvalidConfigurationException::at('server.reactor', sprintf(
                "io_uring requested but unavailable (kernel %s, needs >= %s); OpenSwoole aborts instead of falling back, use 'epoll'",
                Platform::kernelVersion() ?? 'unknown',
                Platform::MIN_IO_URING_KERNEL,
            ));
        }

        $logLevelName = strtolower(self::string($config, 'log_level', 'warning'));
        if (!array_key_exists($logLevelName, self::LOG_LEVELS)) {
            throw InvalidConfigurationException::at('server.log_level', 'expected one of ' . implode(', ', array_keys(self::LOG_LEVELS)));
        }

        $extra = $config['options'] ?? [];
        if (!is_array($extra)) {
            throw InvalidConfigurationException::at('server.options', 'expected an array of raw OpenSwoole options');
        }
        $extraOptions = [];
        foreach ($extra as $k => $v) {
            if (!is_string($k) || !(is_int($v) || is_bool($v) || is_string($v) || is_float($v))) {
                throw InvalidConfigurationException::at('server.options', 'keys must be strings and values scalars');
            }
            $extraOptions[$k] = $v;
        }

        return new self(
            host: self::string($config, 'host', '127.0.0.1'),
            port: self::int($config, 'port', 8080, 0, 65535),
            mode: $serverMode,
            workers: self::int($config, 'workers', Util::getCPUNum(), 1, 1024),
            taskWorkers: self::int($config, 'task_workers', 0, 0, 1024),
            maxCoroutine: self::int($config, 'max_coroutine', 10000, 1, 1_000_000),
            maxRequest: self::int($config, 'max_request', 0, 0, 10_000_000),
            maxWaitTime: self::int($config, 'max_wait_time', 10, 1, 3600),
            backlog: self::int($config, 'backlog', 512, 1, 65535),
            reactor: $reactor,
            hookFlags: self::int($config, 'hook_flags', Runtime::HOOK_ALL, 0, 0x7FFFFFFF),
            logFile: self::optionalString($config, 'log_file'),
            logLevel: self::LOG_LEVELS[$logLevelName],
            pidFile: self::optionalString($config, 'pid_file'),
            enableReusePort: self::bool($config, 'enable_reuse_port', false),
            reloadAsync: self::bool($config, 'reload_async', true),
            packageMaxLength: self::int($config, 'package_max_length', 2 * 1024 * 1024, 65536, 1024 * 1024 * 1024),
            bufferOutputSize: self::int($config, 'buffer_output_size', 2 * 1024 * 1024, 1, 1024 * 1024 * 1024),
            tcpNodelay: self::bool($config, 'tcp_nodelay', true),
            http2: self::bool($config, 'http2', false),
            daemonize: self::bool($config, 'daemonize', false),
            extraOptions: $extraOptions,
        );
    }

    /**
     * The OpenSwoole `Server::set()` array. Explicit options win over `extraOptions`.
     *
     * @return array<string, scalar>
     */
    public function toOpenSwooleSettings(): array
    {
        $settings = [
            'worker_num' => $this->workers,
            'task_worker_num' => $this->taskWorkers,
            'max_coroutine' => $this->maxCoroutine,
            'max_request' => $this->maxRequest,
            'max_wait_time' => $this->maxWaitTime,
            'backlog' => $this->backlog,
            'hook_flags' => $this->hookFlags,
            'enable_coroutine' => true,
            'task_enable_coroutine' => true,
            'log_level' => $this->logLevel,
            'enable_reuse_port' => $this->enableReusePort,
            'reload_async' => $this->reloadAsync,
            'package_max_length' => $this->packageMaxLength,
            'buffer_output_size' => $this->bufferOutputSize,
            'open_tcp_nodelay' => $this->tcpNodelay,
            'open_http2_protocol' => $this->http2,
            'daemonize' => $this->daemonize,
            'enable_server_token' => false,
        ];

        if ($this->logFile !== null) {
            $settings['log_file'] = $this->logFile;
        }
        if ($this->pidFile !== null) {
            $settings['pid_file'] = $this->pidFile;
        }

        return $settings + $this->extraOptions;
    }

    /** @param array<string, mixed> $c */
    private static function string(array $c, string $key, string $default): string
    {
        $v = $c[$key] ?? $default;
        if (!is_string($v)) {
            throw InvalidConfigurationException::at('server.' . $key, 'expected string');
        }

        return $v;
    }

    /** @param array<string, mixed> $c */
    private static function optionalString(array $c, string $key): ?string
    {
        $v = $c[$key] ?? null;
        if ($v !== null && (!is_string($v) || $v === '')) {
            throw InvalidConfigurationException::at('server.' . $key, 'expected non-empty string or null');
        }

        return $v;
    }

    /** @param array<string, mixed> $c */
    private static function int(array $c, string $key, int $default, int $min, int $max): int
    {
        $v = $c[$key] ?? $default;
        if (!is_int($v)) {
            throw InvalidConfigurationException::at('server.' . $key, 'expected integer');
        }
        if ($v < $min || $v > $max) {
            throw InvalidConfigurationException::at('server.' . $key, "expected integer between {$min} and {$max}, got {$v}");
        }

        return $v;
    }

    /** @param array<string, mixed> $c */
    private static function bool(array $c, string $key, bool $default): bool
    {
        $v = $c[$key] ?? $default;
        if (!is_bool($v)) {
            throw InvalidConfigurationException::at('server.' . $key, 'expected boolean');
        }

        return $v;
    }
}
