<?php

declare(strict_types=1);

namespace Omnitrix\Maintenance;

use InvalidArgumentException;
use Omnitrix\Core\IO\Filesystem;
use Omnitrix\Core\Redis\RedisClient;
use Throwable;

use function dirname;
use function getcwd;
use function hash;
use function hash_equals;
use function is_array;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;
use function microtime;
use function time;

use const JSON_THROW_ON_ERROR;

/**
 * Whether the application is down for maintenance (`php omnitrix down` / `up`).
 *
 * The `file` driver keeps the state in a file (one server); the `redis` driver in Redis, so one
 * `down` takes every server down. Workers read it at most once per second.
 *
 * Config `app.maintenance`: driver (file|redis), path (file), except (path patterns served anyway).
 */
final class MaintenanceMode
{
    public const string KEY = 'omnitrix:maintenance';

    /** @var array<string, mixed>|null */
    private ?array $cached = null;

    private float $checkedAt = 0.0;

    private readonly Filesystem $io;

    /** @param list<string> $except */
    public function __construct(
        private readonly string $driver = 'file',
        private readonly ?string $path = null,
        private readonly ?RedisClient $redis = null,
        public readonly array $except = ['/health/*', '/metrics'],
        private readonly float $refreshSeconds = 1.0,
    ) {
        if ($driver !== 'file' && $driver !== 'redis') {
            throw new InvalidArgumentException("app.maintenance.driver must be 'file' or 'redis'");
        }
        $this->io = new Filesystem();
    }

    /** @param array<string, mixed> $config `app.maintenance` */
    public static function fromConfig(array $config, ?RedisClient $redis): self
    {
        $driver = is_string($config['driver'] ?? null) ? $config['driver'] : 'file';
        $path = is_string($config['path'] ?? null) ? $config['path'] : null;
        $except = ['/health/*', '/metrics'];
        if (is_array($config['except'] ?? null)) {
            $except = [];
            foreach ($config['except'] as $pattern) {
                if (is_string($pattern)) {
                    $except[] = $pattern;
                }
            }
        }
        if ($driver === 'redis' && $redis === null) {
            throw new InvalidArgumentException("app.maintenance.driver 'redis' needs the redis configuration");
        }

        return new self($driver, $path, $redis, $except);
    }

    /**
     * Takes the application down.
     *
     * @param list<string> $allow client IPs still served
     */
    public function activate(?string $message = null, ?int $retry = null, ?string $secret = null, array $allow = []): void
    {
        $payload = json_encode(['time' => time(), 'message' => $message, 'retry' => $retry, 'secret' => $secret === null ? null : hash('sha256', $secret), 'allow' => $allow], JSON_THROW_ON_ERROR);
        if ($this->driver === 'redis') {
            $this->redis?->set(self::KEY, $payload);
        } else {
            $file = $this->file();
            $this->io->ensureDirectory(dirname($file));
            $this->io->write($file, $payload);
        }
        $this->checkedAt = 0.0;
    }

    public function deactivate(): void
    {
        if ($this->driver === 'redis') {
            $this->redis?->delete(self::KEY);
        } elseif (is_file($this->file())) {
            $this->io->delete($this->file());
        }
        $this->checkedAt = 0.0;
    }

    public function active(): bool
    {
        return $this->state() !== null;
    }

    /**
     * The `down` options, or null when up. Read again at most once per refresh interval.
     *
     * @return array{time?: int, message?: ?string, retry?: ?int, secret?: ?string, allow?: list<string>}|null
     */
    public function state(): ?array
    {
        $now = microtime(true);
        if ($now - $this->checkedAt >= $this->refreshSeconds) {
            $this->checkedAt = $now;
            $this->cached = $this->read();
        }

        /** @var array{time?: int, message?: ?string, retry?: ?int, secret?: ?string, allow?: list<string>}|null */
        return $this->cached;
    }

    /** True for the secret given to `down --secret`. */
    public function bypasses(string $secret): bool
    {
        $hash = $this->state()['secret'] ?? null;

        return is_string($hash) && $secret !== '' && hash_equals($hash, hash('sha256', $secret));
    }

    public function file(): string
    {
        return $this->path ?? ((getcwd() === false ? '.' : getcwd()) . '/storage/framework/down');
    }

    /** @return array<string, mixed>|null */
    private function read(): ?array
    {
        try {
            if ($this->driver === 'redis') {
                $raw = $this->redis?->get(self::KEY);
            } else {
                $raw = is_file($this->file()) ? $this->io->read($this->file()) : null;
            }
        } catch (Throwable) {
            return $this->cached; // keep the last known state when the store is unreachable
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $state = json_decode($raw, true);

        /** @var array<string, mixed> */
        return is_array($state) ? $state : ['message' => null];
    }
}
