<?php

declare(strict_types=1);

namespace Omnitrix\Mail\Transport;

use Omnitrix\Mail\MailException;
use Omnitrix\Mail\Message;

use function base64_encode;
use function fclose;
use function fgets;
use function fwrite;
use function in_array;
use function is_resource;
use function is_string;
use function preg_match;
use function preg_replace;
use function str_contains;
use function stream_context_create;
use function stream_set_timeout;
use function stream_socket_client;
use function stream_socket_enable_crypto;
use function strlen;
use function strtoupper;
use function substr;
use function trim;

use const STREAM_CLIENT_CONNECT;
use const STREAM_CRYPTO_METHOD_TLS_CLIENT;

/**
 * SMTP over the runtime's coroutine sockets: sending suspends only the calling coroutine.
 * `encryption`: `tls` (STARTTLS, port 587), `ssl` (implicit TLS, port 465) or `none`.
 * Authenticates with AUTH PLAIN or LOGIN when a username is configured.
 */
final class SmtpTransport implements Transport
{
    /** @var resource|null */
    private $socket = null;

    /** @param array{verify_peer?: bool} $tls */
    public function __construct(
        private readonly string $host,
        private readonly int $port = 587,
        private readonly string $encryption = 'tls',
        private readonly ?string $username = null,
        private readonly ?string $password = null,
        private readonly float $timeout = 10.0,
        private readonly string $localDomain = 'localhost',
        private readonly array $tls = [],
    ) {}

    public function send(Message $message): void
    {
        $from = $message->getFrom() ?? throw new MailException('The message has no sender');
        $mime = $message->toMime();
        try {
            $this->connect();
            $this->command("MAIL FROM:<{$from->email}>", [250]);
            foreach ($message->recipients() as $recipient) {
                $this->command("RCPT TO:<{$recipient->email}>", [250, 251]);
            }
            $this->command('DATA', [354]);
            // Dot-stuffing: a line starting with "." gets another one.
            $this->write((string) preg_replace('/^\./m', '..', $mime) . "\r\n.\r\n");
            $this->expect([250]);
            $this->command('QUIT', [221]);
        } finally {
            $this->close();
        }
    }

    private function connect(): void
    {
        $scheme = $this->encryption === 'ssl' ? 'ssl' : 'tcp';
        $context = stream_context_create(['ssl' => ['verify_peer' => $this->tls['verify_peer'] ?? true, 'verify_peer_name' => $this->tls['verify_peer'] ?? true, 'peer_name' => $this->host]]);
        $socket = @stream_socket_client("{$scheme}://{$this->host}:{$this->port}", $errno, $error, $this->timeout, STREAM_CLIENT_CONNECT, $context);
        if (!is_resource($socket)) {
            throw new MailException("Could not connect to the SMTP server {$this->host}:{$this->port}: {$error}");
        }
        stream_set_timeout($socket, (int) $this->timeout, (int) (($this->timeout - (int) $this->timeout) * 1e6));
        $this->socket = $socket;
        $this->expect([220]);
        $features = $this->command("EHLO {$this->localDomain}", [250]);
        if ($this->encryption === 'tls') {
            if (!str_contains($features, 'STARTTLS')) {
                throw new MailException('The SMTP server does not offer STARTTLS (set encryption to none or ssl)');
            }
            $this->command('STARTTLS', [220]);
            if (stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                throw new MailException('TLS negotiation with the SMTP server failed');
            }
            $features = $this->command("EHLO {$this->localDomain}", [250]);
        }
        if ($this->username !== null && $this->username !== '') {
            $password = $this->password ?? '';
            if (preg_match('/AUTH[ =][^\r\n]*PLAIN/i', $features) === 1) {
                $this->command('AUTH PLAIN ' . base64_encode("\0{$this->username}\0{$password}"), [235]);
            } else {
                $this->command('AUTH LOGIN', [334]);
                $this->command(base64_encode($this->username), [334]);
                $this->command(base64_encode($password), [235]);
            }
        }
    }

    /**
     * @param list<int> $expected
     *
     * @return string the reply
     */
    private function command(string $line, array $expected): string
    {
        $this->write($line . "\r\n");

        return $this->expect($expected, $line);
    }

    /** @param list<int> $expected */
    private function expect(array $expected, string $command = ''): string
    {
        $reply = '';
        while (true) {
            $line = is_resource($this->socket) ? fgets($this->socket, 1024) : false;
            if (!is_string($line)) {
                throw new MailException('The SMTP server closed the connection' . ($command !== '' ? ' after ' . self::redact($command) : ''));
            }
            $reply .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break; // last line of the reply
            }
        }
        $code = (int) substr($reply, 0, 3);
        if (!in_array($code, $expected, true)) {
            throw new MailException('SMTP error' . ($command !== '' ? ' after ' . self::redact($command) : '') . ': ' . trim($reply));
        }

        return strtoupper($reply);
    }

    private function write(string $data): void
    {
        if (!is_resource($this->socket) || fwrite($this->socket, $data) === false) {
            throw new MailException('Writing to the SMTP server failed');
        }
    }

    private function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->socket = null;
    }

    private static function redact(string $command): string
    {
        return str_contains($command, 'AUTH') || preg_match('/^[A-Za-z0-9+\/=]+$/', $command) === 1 ? '(credentials)' : $command;
    }
}
