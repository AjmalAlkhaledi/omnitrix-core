<?php

declare(strict_types=1);

namespace Omnitrix\Core\HttpClient\Transport;

use Omnitrix\Core\HttpClient\Exception\ConnectionException;
use Omnitrix\Core\HttpClient\Exception\DnsException;
use Omnitrix\Core\HttpClient\Exception\HttpClientException;
use Omnitrix\Core\HttpClient\Exception\TimeoutException;
use Omnitrix\Core\HttpClient\Exception\TlsException;
use Omnitrix\Core\HttpClient\Uri;
use OpenSwoole\Coroutine;

/**
 * Maps OpenSwoole client failures (errCode / statusCode) to typed exceptions. Codes verified in
 * on OpenSwoole 26.2: 110 timeout, 111 refused, 104 reset, 125 cancelled, 700–712 DNS failure
 * (711 resolve failed, 712 resolve timeout),
 * 1014 TLS verification failed; statusCode -1 connect failure, -2 timeout, -3 server closed.
 *
 * @internal
 */
final class ErrorMapper
{
    private function __construct() {}

    public static function map(int $errCode, string $errMsg, int $statusCode, Uri $uri, string $phase): HttpClientException
    {
        $target = $uri->origin();
        if ($errCode === 125 || Coroutine::isCanceled()) {
            return new CancelledOutboundException("Request to {$target} was cancelled");
        }

        return match (true) {
            $errCode === 110 || $statusCode === -2 => new TimeoutException("Timed out ({$phase}) talking to {$target}" . ($errMsg !== '' ? ": {$errMsg}" : ''), $errCode),
            $errCode >= 700 && $errCode <= 712 => new DnsException("Could not resolve host {$uri->host}" . ($errMsg !== '' ? ": {$errMsg}" : ''), $errCode),
            $errCode >= 1010 && $errCode <= 1018 => new TlsException("TLS failure with {$target}: " . ($errMsg !== '' ? $errMsg : 'handshake failed') . " (verify the certificate chain or set ca_file)", $errCode),
            $errCode === 111 || $errCode === 113 || $errCode === 101 => new ConnectionException("Connection to {$target} failed: " . ($errMsg !== '' ? $errMsg : 'refused'), $errCode),
            $errCode === 104 || $errCode === 32 || $statusCode === -3 => new ConnectionException("Connection to {$target} was closed by the peer before a complete response arrived", $errCode),
            default => new ConnectionException("Request to {$target} failed in {$phase} (errno {$errCode}" . ($errMsg !== '' ? ": {$errMsg}" : '') . ')', $errCode),
        };
    }
}
