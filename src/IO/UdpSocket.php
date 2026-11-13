<?php

declare(strict_types=1);

namespace Omnitrix\Core\IO;

use OpenSwoole\Coroutine\Socket;

use function is_array;
use function is_int;
use function is_string;

use const AF_INET;
use const SOCK_DGRAM;

/** Coroutine UDP socket (datagrams; no delivery guarantee). */
final class UdpSocket
{
    private readonly Socket $socket;

    public function __construct(string $bindHost = '0.0.0.0', int $bindPort = 0)
    {
        $this->socket = new Socket(AF_INET, SOCK_DGRAM, 0);
        if ($this->socket->bind($bindHost, $bindPort) !== true) {
            $code = is_int($this->socket->errCode) ? $this->socket->errCode : 0;
            throw new IoException("Cannot bind UDP {$bindHost}:{$bindPort} (errno {$code})", $code);
        }
    }

    public function port(): int
    {
        $name = $this->socket->getsockname();

        return is_array($name) && is_int($name['port'] ?? null) ? $name['port'] : 0;
    }

    public function sendTo(string $address, int $port, string $data): void
    {
        $sent = $this->socket->sendto($address, $port, $data);
        if ($sent === false) {
            throw new IoException("UDP send to {$address}:{$port} failed");
        }
    }

    /**
     * Waits for one datagram.
     *
     * @return array{data: string, address: string, port: int}
     *
     * @throws IoTimeoutException
     */
    public function receive(float $timeout = 5.0): array
    {
        $peer = null;
        $data = $this->socket->recvfrom($peer, $timeout);
        if (!is_string($data) || !is_array($peer)) {
            throw new IoTimeoutException("No datagram within {$timeout}s");
        }

        return ['data' => $data, 'address' => is_string($peer['address'] ?? null) ? $peer['address'] : '', 'port' => is_int($peer['port'] ?? null) ? $peer['port'] : 0];
    }

    public function close(): void
    {
        $this->socket->close();
    }
}
