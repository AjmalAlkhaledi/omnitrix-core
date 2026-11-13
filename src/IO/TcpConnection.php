<?php

declare(strict_types=1);

namespace Omnitrix\Core\IO;

use OpenSwoole\Coroutine\Socket;

use function is_array;
use function is_int;
use function is_string;
use function strlen;

use const AF_INET;
use const SOCK_STREAM;

/**
 * A connected TCP stream on an OpenSwoole coroutine socket. Every wait suspends only the
 * calling coroutine. Not shareable between coroutines (one reader, one writer at a time).
 */
final class TcpConnection
{
    private bool $closed = false;

    public function __construct(private readonly Socket $socket) {}

    /** @throws IoException|IoTimeoutException */
    public static function connect(string $host, int $port, float $timeout = 5.0, ?Dns $dns = null): self
    {
        $address = ($dns ?? new Dns())->resolve($host, $timeout);
        $socket = new Socket(AF_INET, SOCK_STREAM, 0);
        if ($socket->connect($address, $port, $timeout) !== true) {
            $code = is_int($socket->errCode) ? $socket->errCode : 0;
            $socket->close();
            throw $code === 110 ? new IoTimeoutException("Connecting to {$host}:{$port} timed out") : new IoException("Could not connect to {$host}:{$port} (errno {$code})", $code);
        }

        return new self($socket);
    }

    /** Sends all bytes. */
    public function send(string $data, float $timeout = 5.0): void
    {
        $this->assertOpen();
        $sent = $this->socket->sendAll($data, $timeout);
        if (!is_int($sent) || $sent !== strlen($data)) {
            $this->fail('send', $timeout);
        }
    }

    /** Up to `$maxBytes` bytes as soon as any arrive; '' when the peer closed. */
    public function receive(int $maxBytes = 65536, float $timeout = 5.0): string
    {
        $this->assertOpen();
        $data = $this->socket->recv($maxBytes, $timeout);
        if ($data === false) {
            $this->fail('receive', $timeout);
        }

        return is_string($data) ? $data : '';
    }

    /** Exactly `$bytes` bytes, or an exception. */
    public function receiveExactly(int $bytes, float $timeout = 5.0): string
    {
        $this->assertOpen();
        $data = $this->socket->recvAll($bytes, $timeout);
        if ($data === false) {
            $this->fail('receive', $timeout);
        }
        $data = is_string($data) ? $data : '';
        if (strlen($data) !== $bytes) {
            throw new ConnectionClosedException("Peer closed after " . strlen($data) . " of {$bytes} bytes");
        }

        return $data;
    }

    /** One line including the trailing "\n" (max `$maxBytes`); '' when the peer closed. */
    public function receiveLine(int $maxBytes = 65536, float $timeout = 5.0): string
    {
        $this->assertOpen();
        $data = $this->socket->recvLine($maxBytes, $timeout);
        if ($data === false) {
            $this->fail('receive', $timeout);
        }

        return is_string($data) ? $data : '';
    }

    /** @return array{address: string, port: int}|null */
    public function remote(): ?array
    {
        $peer = $this->socket->getpeername();

        return is_array($peer) && is_string($peer['address'] ?? null) && is_int($peer['port'] ?? null) ? ['address' => $peer['address'], 'port' => $peer['port']] : null;
    }

    public function close(): void
    {
        if (!$this->closed) {
            $this->closed = true;
            $this->socket->close();
        }
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new ConnectionClosedException('Connection already closed');
        }
    }

    private function fail(string $operation, float $timeout): never
    {
        $code = is_int($this->socket->errCode) ? $this->socket->errCode : 0;
        if ($code === 110 || $code === 11) {
            throw new IoTimeoutException("{$operation} timed out after {$timeout}s");
        }
        if ($code === 104 || $code === 32) {
            throw new ConnectionClosedException("Connection reset during {$operation}");
        }

        throw new IoException("{$operation} failed (errno {$code})", $code);
    }
}
