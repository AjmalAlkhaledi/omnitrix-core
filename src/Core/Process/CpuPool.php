<?php

declare(strict_types=1);

namespace Omnitrix\Core\Process;

use Omnitrix\Core\Concurrency\Semaphore;
use Omnitrix\Core\Concurrency\TimeoutException as SemaphoreTimeout;
use OpenSwoole\Coroutine\Socket;
use OpenSwoole\Process;

use function hrtime;
use function is_int;
use function is_string;
use function max;
use function strlen;

use const AF_UNIX;
use const SIGKILL;
use const SOCK_STREAM;

/**
 * Client side of the CPU pool, used from HTTP workers (or any coroutine).
 *
 * `run()` sends the job to the least-busy CPU worker (shared {@see CpuLoadBoard}) and waits
 * cooperatively: the HTTP worker's event loop keeps serving other requests while the CPU
 * process computes. Timeouts that hit a running job kill that process (the manager restarts
 * it); timeouts while still queued just abandon the request.
 */
final class CpuPool
{
    private readonly Semaphore $pending;

    /** @var array<string, int> */
    private array $stats = ['submitted' => 0, 'completed' => 0, 'failed' => 0, 'timeouts' => 0, 'crashes' => 0, 'killed' => 0];

    public function __construct(public readonly CpuPoolConfig $config, private readonly CpuLoadBoard $board)
    {
        $this->pending = new Semaphore($config->maxPending);
    }

    /**
     * Runs a registered job with JSON-compatible arguments and returns its JSON-compatible result.
     *
     * @param array<mixed> $args
     *
     * @throws CpuTimeoutException|WorkerCrashedException|CpuJobException|CpuException
     */
    public function run(string $job, array $args = [], ?float $timeout = null): mixed
    {
        $timeout ??= $this->config->timeout;
        $deadline = hrtime(true) + (int) ($timeout * 1e9);
        try {
            $this->pending->acquire($timeout);
        } catch (SemaphoreTimeout $e) {
            $this->stats['timeouts']++;
            throw new CpuTimeoutException("CPU pool saturated: {$this->config->maxPending} jobs pending", 0, $e);
        }
        $index = $this->board->pick();
        $this->board->increment($index);
        $this->stats['submitted']++;
        try {
            return $this->exchange($index, $job, $args, $deadline, $timeout);
        } finally {
            $this->board->decrement($index);
            $this->pending->release();
        }
    }

    /** @return array<string, int> */
    public function stats(): array
    {
        return $this->stats + ['pending' => $this->pending->inUse()];
    }

    /** @param array<mixed> $args */
    private function exchange(int $index, string $job, array $args, int $deadline, float $timeout): mixed
    {
        $socket = new Socket(AF_UNIX, SOCK_STREAM, 0);
        try {
            if ($socket->connect($this->config->socketPath($index), 0, self::remaining($deadline)) !== true) {
                $this->stats['crashes']++;
                throw new WorkerCrashedException("CPU worker {$index} is not accepting jobs (restarting?)");
            }
            if ($socket->sendAll(Frame::encode(['job' => $job, 'args' => $args]), self::remaining($deadline)) === false) {
                throw new WorkerCrashedException("CPU worker {$index} closed the connection");
            }

            $ack = $this->readFrame($socket, $deadline);
            if ($ack === null) {
                $this->stats['timeouts']++;
                throw new CpuTimeoutException("CPU job '{$job}' was still queued after {$timeout}s");
            }
            $pid = is_int($ack['pid'] ?? null) ? $ack['pid'] : 0;

            $reply = $this->readFrame($socket, $deadline);
            if ($reply === null) {
                $this->stats['timeouts']++;
                if ($pid > 0 && Process::kill($pid, SIGKILL)) {
                    $this->stats['killed']++;
                }
                throw new CpuTimeoutException("CPU job '{$job}' exceeded {$timeout}s; worker {$index} (pid {$pid}) was killed and will be restarted");
            }
            if (($reply['ok'] ?? false) === true) {
                $this->stats['completed']++;

                return $reply['result'] ?? null;
            }
            $this->stats['failed']++;
            throw new CpuJobException(is_string($reply['error'] ?? null) ? $reply['error'] : 'CPU job failed', is_string($reply['class'] ?? null) ? $reply['class'] : CpuException::class);
        } finally {
            $socket->close();
        }
    }

    /**
     * Reads one frame; null on timeout; throws WorkerCrashedException when the peer vanished.
     *
     * @return array<string, mixed>|null
     */
    private function readFrame(Socket $socket, int $deadline): ?array
    {
        $header = $socket->recvAll(4, self::remaining($deadline));
        if ($header === false && $socket->errCode === 110) {
            return null;
        }
        if (!is_string($header) || strlen($header) !== 4) {
            $this->stats['crashes']++;
            throw new WorkerCrashedException('CPU worker process died while running the job');
        }
        $body = $socket->recvAll(Frame::length($header), self::remaining($deadline));
        if ($body === false && $socket->errCode === 110) {
            return null;
        }
        if (!is_string($body)) {
            $this->stats['crashes']++;
            throw new WorkerCrashedException('CPU worker process died while sending the result');
        }

        return Frame::decode($body);
    }

    private static function remaining(int $deadline): float
    {
        return max(0.001, ($deadline - hrtime(true)) / 1e9);
    }
}
