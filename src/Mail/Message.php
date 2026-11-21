<?php

declare(strict_types=1);

namespace Omnitrix\Mail;

use InvalidArgumentException;

use function array_map;
use function basename;
use function bin2hex;
use function count;
use function date;
use function file_get_contents;
use function implode;
use function is_file;
use function is_string;
use function preg_match;
use function random_bytes;
use function strrchr;
use function substr;

/**
 * An e-mail message, built fluently and rendered to MIME.
 *
 * ```php
 * (new Message())->to('ann@example.com', 'Ann')->subject('Welcome')->text('Hi Ann')->html('<p>Hi Ann</p>');
 * ```
 */
final class Message
{
    private ?Address $from = null;

    /** @var list<Address> */
    private array $to = [];

    /** @var list<Address> */
    private array $cc = [];

    /** @var list<Address> */
    private array $bcc = [];

    private ?Address $replyTo = null;

    private string $subject = '';

    private ?string $text = null;

    private ?string $html = null;

    /** @var list<array{string, string, string}> data, file name, MIME type */
    private array $attachments = [];

    /** @var array<string, string> */
    private array $headers = [];

    public function from(string $email, string $name = ''): self
    {
        $this->from = new Address($email, $name);

        return $this;
    }

    public function to(string $email, string $name = ''): self
    {
        $this->to[] = new Address($email, $name);

        return $this;
    }

    public function cc(string $email, string $name = ''): self
    {
        $this->cc[] = new Address($email, $name);

        return $this;
    }

    public function bcc(string $email, string $name = ''): self
    {
        $this->bcc[] = new Address($email, $name);

        return $this;
    }

    public function replyTo(string $email, string $name = ''): self
    {
        $this->replyTo = new Address($email, $name);

        return $this;
    }

    public function subject(string $subject): self
    {
        if (preg_match('/[\r\n]/', $subject) === 1) {
            throw new InvalidArgumentException('The subject must be one line');
        }
        $this->subject = $subject;

        return $this;
    }

    public function text(string $text): self
    {
        $this->text = $text;

        return $this;
    }

    public function html(string $html): self
    {
        $this->html = $html;

        return $this;
    }

    /** Attaches a file from disk. */
    public function attach(string $path, ?string $name = null, string $mime = 'application/octet-stream'): self
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException("Attachment {$path} does not exist");
        }
        $data = file_get_contents($path);

        return $this->attachData(is_string($data) ? $data : '', $name ?? basename($path), $mime);
    }

    public function attachData(string $data, string $name, string $mime = 'application/octet-stream'): self
    {
        if (preg_match('/[\r\n"]/', $name . $mime) === 1) {
            throw new InvalidArgumentException('Invalid attachment name or type');
        }
        $this->attachments[] = [$data, $name, $mime];

        return $this;
    }

    public function header(string $name, string $value): self
    {
        if (preg_match('/^[A-Za-z0-9-]+$/', $name) !== 1 || preg_match('/[\r\n]/', $value) === 1) {
            throw new InvalidArgumentException("Invalid header {$name}");
        }
        $this->headers[$name] = $value;

        return $this;
    }

    public function getFrom(): ?Address
    {
        return $this->from;
    }

    /** @return list<Address> */
    public function getTo(): array
    {
        return $this->to;
    }

    /** @return list<Address> */
    public function getCc(): array
    {
        return $this->cc;
    }

    /** @return list<Address> */
    public function getBcc(): array
    {
        return $this->bcc;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function getHtml(): ?string
    {
        return $this->html;
    }

    /** @return list<array{string, string, string}> */
    public function getAttachments(): array
    {
        return $this->attachments;
    }

    /** @return list<Address> every recipient (to, cc, bcc): the SMTP envelope */
    public function recipients(): array
    {
        return [...$this->to, ...$this->cc, ...$this->bcc];
    }

    /** The message in MIME form (headers and body, CRLF line endings; Bcc is not included). */
    public function toMime(): string
    {
        if ($this->from === null) {
            throw new InvalidArgumentException('The message has no sender (set mail.from)');
        }
        if ($this->recipients() === []) {
            throw new InvalidArgumentException('The message has no recipient');
        }
        $domain = substr((string) strrchr($this->from->email, '@'), 1);
        $headers = [
            'Date' => date('r'),
            'Message-ID' => '<' . bin2hex(random_bytes(12)) . '@' . ($domain !== '' ? $domain : 'localhost') . '>',
            'From' => $this->from->toString(),
        ];
        if ($this->to !== []) {
            $headers['To'] = implode(', ', array_map(static fn(Address $a): string => $a->toString(), $this->to));
        }
        if ($this->cc !== []) {
            $headers['Cc'] = implode(', ', array_map(static fn(Address $a): string => $a->toString(), $this->cc));
        }
        if ($this->replyTo !== null) {
            $headers['Reply-To'] = $this->replyTo->toString();
        }
        $headers['Subject'] = Mime::encodeHeader($this->subject);
        $headers['MIME-Version'] = '1.0';
        $headers += $this->headers;

        [$contentType, $body] = $this->body();
        $lines = [];
        foreach ($headers + ['Content-Type' => $contentType] as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return implode("\r\n", $lines) . "\r\n" . $body;
    }

    /** @return array{string, string} content type (with parameters) and the part's body, starting with its own headers */
    private function body(): array
    {
        $text = $this->text;
        $html = $this->html;
        $parts = [];
        if ($text !== null) {
            $parts[] = ['text/plain; charset=utf-8', "Content-Transfer-Encoding: quoted-printable\r\n\r\n" . Mime::crlf(Mime::quotedPrintable($text))];
        }
        if ($html !== null) {
            $parts[] = ['text/html; charset=utf-8', "Content-Transfer-Encoding: quoted-printable\r\n\r\n" . Mime::crlf(Mime::quotedPrintable($html))];
        }
        if ($parts === []) {
            $parts[] = ['text/plain; charset=utf-8', "Content-Transfer-Encoding: quoted-printable\r\n\r\n"];
        }
        $content = count($parts) === 1 ? $parts[0] : self::multipart('alternative', $parts);
        if ($this->attachments === []) {
            return $content;
        }
        $mixed = [$content];
        foreach ($this->attachments as [$data, $name, $mime]) {
            $mixed[] = [$mime . '; name="' . $name . '"', "Content-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"{$name}\"\r\n\r\n" . Mime::base64($data)];
        }

        return self::multipart('mixed', $mixed);
    }

    /**
     * @param list<array{string, string}> $parts
     *
     * @return array{string, string}
     */
    private static function multipart(string $subtype, array $parts): array
    {
        $boundary = '=_' . bin2hex(random_bytes(12));
        $body = "\r\n";
        foreach ($parts as [$type, $part]) {
            $body .= "--{$boundary}\r\nContent-Type: {$type}\r\n{$part}\r\n";
        }
        $body .= "--{$boundary}--\r\n";

        return ["multipart/{$subtype}; boundary=\"{$boundary}\"", $body];
    }
}
