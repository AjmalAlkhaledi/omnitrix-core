<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Integration\Mail;

use InvalidArgumentException;
use Omnitrix\Mail\Mailable;
use Omnitrix\Mail\Mailer;
use Omnitrix\Mail\MailException;
use Omnitrix\Mail\Message;
use Omnitrix\Mail\Transport\ArrayTransport;
use Omnitrix\Mail\Transport\SmtpTransport;
use Omnitrix\Tests\Support\TestCase;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use PHPUnit\Framework\Attributes\CoversClass;
use stdClass;

use function base64_decode;
use function base64_encode;
use function count;
use function fclose;
use function fread;
use function fwrite;
use function is_resource;
use function random_int;
use function str_contains;
use function str_starts_with;
use function stream_select;
use function stream_socket_accept;
use function stream_socket_server;
use function strpos;
use function strtoupper;
use function substr;

final class WelcomeMail extends Mailable
{
    public function __construct(public readonly string $name) {}

    public function build(Message $message): void
    {
        $message->subject("Welcome, {$this->name}")->text("Hi {$this->name}\n.\nBye")->html("<p>Hi {$this->name}</p>");
    }
}

final class SmtpExchange
{
    /** @var list<string> */
    public array $commands = [];

    public string $data = '';

    public function last(): ?string
    {
        return $this->commands === [] ? null : $this->commands[count($this->commands) - 1];
    }
}

#[CoversClass(Message::class)]
#[CoversClass(Mailer::class)]
#[CoversClass(SmtpTransport::class)]
final class MailTest extends TestCase
{
    public function testMimeRendering(): void
    {
        $mime = (new Message())
            ->from('hello@example.com', 'Example, Inc.')
            ->to('ann@example.com', 'Änn')
            ->bcc('audit@example.com')
            ->subject('Grüße')
            ->text('plain')
            ->html('<b>rich</b>')
            ->attachData('a,b', 'report.csv', 'text/csv')
            ->toMime();
        self::assertStringContainsString('From: "Example, Inc." <hello@example.com>', $mime);
        self::assertStringContainsString('To: =?UTF-8?B?' . base64_encode('Änn') . '?= <ann@example.com>', $mime);
        self::assertStringContainsString('Subject: =?UTF-8?B?' . base64_encode('Grüße') . '?=', $mime);
        self::assertStringNotContainsString('audit@example.com', $mime, 'Bcc is only in the envelope');
        self::assertStringContainsString('multipart/mixed', $mime);
        self::assertStringContainsString('multipart/alternative', $mime);
        self::assertStringContainsString('Content-Disposition: attachment; filename="report.csv"', $mime);
        self::assertStringContainsString(base64_encode('a,b'), $mime);

        foreach ([static fn() => (new Message())->subject("x\r\nBcc: evil@x"), static fn() => (new Message())->to("a@x.com\r\n"), static fn() => (new Message())->header('X-A', "b\nc")] as $inject) {
            try {
                $inject();
                self::fail('header injection accepted');
            } catch (InvalidArgumentException) {
                self::assertTrue(str_starts_with('ok', 'o'));
            }
        }
    }

    public function testMailablesQueueingAndTheDefaultSender(): void
    {
        $transport = new ArrayTransport();
        $jobs = [];
        $mailer = new Mailer($transport, new \Omnitrix\Mail\Address('hello@example.com', 'Example'), static function (string $job, array $payload) use (&$jobs): void {
            $jobs[] = [$job, $payload];
        });
        $mailer->send(new WelcomeMail('Ann'), 'Ann <ann@example.com>');
        self::assertCount(1, $transport->messages);
        self::assertSame('Welcome, Ann', $transport->messages[0]->getSubject());
        self::assertSame('ann@example.com', $transport->messages[0]->getTo()[0]->email);
        self::assertSame('hello@example.com', $transport->messages[0]->getFrom()?->email, 'the configured sender');

        $mailer->queue(new WelcomeMail('Bob'), 'bob@example.com');
        self::assertCount(1, $transport->messages, 'not sent during the request');
        self::assertSame(Mailer::QUEUED_MAIL_JOB, $jobs[0][0]);
        $mailer->handleQueued($jobs[0][1]);
        self::assertSame('Welcome, Bob', $transport->messages[1]->getSubject(), 'the worker sends it');
    }

    public function testSmtpDeliveryWithAuthentication(): void
    {
        $port = 25000 + random_int(0, 4000);
        $server = stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $error);
        self::assertIsResource($server, (string) $error);
        $received = new SmtpExchange();
        $running = new stdClass();
        $running->on = true;
        $stopped = new Channel(1);
        Coroutine::create(static function () use ($server, $received, $running, $stopped): void {
            while ($running->on) {
                $read = [$server];
                $none = null;
                if (stream_select($read, $none, $none, 0, 200_000) > 0) {
                    $conn = stream_socket_accept($server, 1.0);
                    if (is_resource($conn)) {
                        Coroutine::create(static fn() => self::serve($conn, $received));
                    }
                }
            }
            fclose($server);
            $stopped->push(true);
        });
        try {
            $smtp = new SmtpTransport('127.0.0.1', $port, 'none', 'user', 'secret', 5.0, 'app.test');
            $message = (new Message())->from('hello@example.com')->to('ann@example.com')->cc('cc@example.com')->subject('Report')->text("line one\n.leading dot\nend");
            $smtp->send($message);
            self::assertContains('EHLO app.test', $received->commands);
            self::assertContains('MAIL FROM:<hello@example.com>', $received->commands);
            self::assertContains('RCPT TO:<ann@example.com>', $received->commands);
            self::assertContains('RCPT TO:<cc@example.com>', $received->commands);
            self::assertStringContainsString('Subject: Report', $received->data);
            self::assertStringContainsString("\r\n..leading dot", $received->data, 'dot-stuffing');
            self::assertSame('QUIT', $received->last());

            try {
                (new SmtpTransport('127.0.0.1', $port, 'none', 'user', 'wrong', 5.0))->send($message);
                self::fail('bad credentials accepted');
            } catch (MailException $e) {
                self::assertStringContainsString('535', $e->getMessage());
                self::assertStringNotContainsString('wrong', $e->getMessage(), 'credentials are not in errors');
            }
            $this->expectException(MailException::class);
            $this->expectExceptionMessage('550');
            $smtp->send((new Message())->from('hello@example.com')->to('blocked@example.com')->text('x'));
        } finally {
            $running->on = false;
            $stopped->pop(2.0);
        }
    }

    /** A minimal SMTP server for one connection: AUTH PLAIN user/secret, rejects blocked@ recipients. */
    private static function serve(mixed $conn, SmtpExchange $received): void
    {
        if (!is_resource($conn)) {
            return;
        }
        fwrite($conn, "220 fake ESMTP\r\n");
        $buffer = '';
        $inData = false;
        while (true) {
            $chunk = fread($conn, 8192);
            if ($chunk === '' || $chunk === false) {
                return;
            }
            $buffer .= $chunk;
            while (true) {
                if ($inData) {
                    $end = strpos($buffer, "\r\n.\r\n");
                    if ($end === false) {
                        break;
                    }
                    $received->data = substr($buffer, 0, $end);
                    $buffer = substr($buffer, $end + 5);
                    $inData = false;
                    fwrite($conn, "250 queued\r\n");
                    continue;
                }
                $pos = strpos($buffer, "\r\n");
                if ($pos === false) {
                    break;
                }
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);
                $received->commands[] = $line;
                $verb = strtoupper(substr($line, 0, 4));
                $reply = match (true) {
                    $verb === 'EHLO' => "250-fake\r\n250 AUTH PLAIN LOGIN\r\n",
                    str_starts_with($line, 'AUTH PLAIN ') => base64_decode(substr($line, 11), true) === "\0user\0secret" ? "235 ok\r\n" : "535 bad credentials\r\n",
                    $verb === 'MAIL' => "250 ok\r\n",
                    $verb === 'RCPT' => str_contains($line, 'blocked@') ? "550 no such user\r\n" : "250 ok\r\n",
                    $verb === 'DATA' => "354 go\r\n",
                    $verb === 'QUIT' => "221 bye\r\n",
                    default => "500 unknown\r\n",
                };
                $inData = $verb === 'DATA';
                fwrite($conn, $reply);
                if ($verb === 'QUIT') {
                    fclose($conn);

                    return;
                }
            }
        }
    }
}
