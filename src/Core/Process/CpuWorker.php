<?php

declare(strict_types=1);

namespace Omnitrix\Core\Process;

use OpenSwoole\Coroutine\Socket;
use Throwable;

use function file_exists;
use function getmypid;
use function is_array;
use function is_string;
use function strlen;
use function unlink;

use const AF_UNIX;
use const SOCK_STREAM;

/**
 * Body of one CPU worker process: listens on a unix socket and executes jobs one at a time.
 *
 * Protocol per connection: request frame {job, args} → ack frame {pid} (sent before running,
 * so the caller knows which process to kill on timeout) → result frame {ok, result} or
 * {ok: false, error, class}. A job that throws keeps the process alive; a fatal error or
 * signal kills it and the OpenSwoole manager starts a replacement.
 *
 * @internal
 */
final class CpuWorker
{
    private bool $running = true;

    /** @param array<string, callable(array<mixed>): mixed> $jobs */
    public function __construct(private readonly CpuPoolConfig $config, private readonly int $index, private readonly array $jobs) {}

    public function run(): void
    {
        $path = $this->config->socketPath($this->index);
        if (file_exists($path)) {
            @unlink($path);
        }
        $listener = new Socket(AF_UNIX, SOCK_STREAM, 0);
        if ($listener->bind($path) !== true || $listener->listen(256) !== true) {
            throw new CpuException("CPU worker {$this->index} cannot listen on {$path}");
        }
        while ($this->running) {
            $connection = $listener->accept(0.5);
            if ($connection instanceof Socket) {
                $this->serve($connection);
            }
        }
        $listener->close();
        @unlink($path);
    }

    public function stop(): void
    {
        $this->running = false;
    }

    private function serve(Socket $connection): void
    {
        try {
            $header = $connection->recvAll(4, 5.0);
            if (!is_string($header) || strlen($header) !== 4) {
                return;
            }
            $body = $connection->recvAll(Frame::length($header), 5.0);
            if (!is_string($body)) {
                return;
            }
            $request = Frame::decode($body);
            if ($connection->sendAll(Frame::encode(['pid' => getmypid()])) === false) {
                return; // caller gave up while the job was queued: skip it
            }
            $job = is_string($request['job'] ?? null) ? $request['job'] : '';
            $args = is_array($request['args'] ?? null) ? $request['args'] : [];
            $handler = $this->jobs[$job] ?? null;
            if ($handler === null) {
                $connection->sendAll(Frame::encode(['ok' => false, 'error' => "Unknown CPU job '{$job}'", 'class' => CpuException::class]));

                return;
            }
            try {
                $result = $handler($args);
                $reply = ['ok' => true, 'result' => $result];
            } catch (Throwable $e) {
                $reply = ['ok' => false, 'error' => $e->getMessage(), 'class' => $e::class];
            }
            $connection->sendAll(Frame::encode($reply));
        } catch (Throwable) {
            // malformed request or caller vanished; the worker keeps serving
        } finally {
            $connection->close();
        }
    }
}
