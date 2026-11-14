<?php

declare(strict_types=1);

namespace Omnitrix\Core\IO;

use Omnitrix\Core\Concurrency\Semaphore;
use Omnitrix\Core\Runtime\Coroutine\Coroutines;
use Omnitrix\Core\Runtime\Exception\ExceptionHandlerInterface;
use OpenSwoole\Coroutine\Socket;

use function is_array;
use function is_int;

use const AF_INET;
use const SO_REUSEADDR;
use const SOCK_STREAM;
use const SOL_SOCKET;

/**
 * Coroutine TCP server for auxiliary listeners inside a worker or standalone coroutine program
 * (admin ports, custom protocols). Each accepted connection runs its handler in a guarded
 * coroutine; `maxConnections` bounds concurrent handlers (accept pauses when saturated).
 */
final class TcpServer
{
    private readonly Socket $socket;

    private bool $running = false;

    private readonly Semaphore $slots;

    private int $accepted = 0;

    public function __construct(string $host, int $port, private readonly ExceptionHandlerInterface $exceptionHandler, int $maxConnections = 1024, int $backlog = 512)
    {
        $this->socket = new Socket(AF_INET, SOCK_STREAM, 0);
        $this->socket->setOption(SOL_SOCKET, SO_REUSEADDR, 1);
        if ($this->socket->bind($host, $port) !== true || $this->socket->listen($backlog) !== true) {
            $code = is_int($this->socket->errCode) ? $this->socket->errCode : 0;
            $this->socket->close();
            throw new IoException("Cannot listen on {$host}:{$port} (errno {$code})", $code);
        }
        $this->slots = new Semaphore($maxConnections);
    }

    /** The bound port (useful with port 0). */
    public function port(): int
    {
        $name = $this->socket->getsockname();

        return is_array($name) && is_int($name['port'] ?? null) ? $name['port'] : 0;
    }

    /**
     * Accept loop; returns after {@see stop()}. Run it in its own coroutine.
     *
     * @param callable(TcpConnection): void $handler
     */
    public function serve(callable $handler): void
    {
        $this->running = true;
        while ($this->isRunning()) {
            $this->slots->acquire();
            $client = $this->socket->accept(0.5);
            if (!$client instanceof Socket) {
                $this->slots->release();
                continue; // timeout tick: re-check $running
            }
            $this->accepted++;
            $connection = new TcpConnection($client);
            Coroutines::spawn(function () use ($handler, $connection): void {
                try {
                    $handler($connection);
                } finally {
                    $connection->close();
                    $this->slots->release();
                }
            }, $this->exceptionHandler, ['origin' => 'tcp.server']);
        }
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    /** Stops accepting (the loop exits within 0.5 s) and closes the listener. */
    public function stop(): void
    {
        $this->running = false;
    }

    public function close(): void
    {
        $this->running = false;
        $this->socket->close();
    }

    public function accepted(): int
    {
        return $this->accepted;
    }

    public function activeConnections(): int
    {
        return $this->slots->inUse();
    }
}
