<?php

declare(strict_types=1);

namespace Omnitrix\Mail;

use Closure;
use InvalidArgumentException;
use LogicException;
use Omnitrix\Mail\Transport\Transport;

use function array_values;
use function base64_decode;
use function base64_encode;
use function is_string;
use function serialize;
use function unserialize;

/** Sends messages and mailables through one transport, with the configured sender. */
final class Mailer implements \Omnitrix\Contracts\Mailer
{
    public const string QUEUED_MAIL_JOB = 'omnitrix.mail';

    /** @param (Closure(string, array<string, mixed>, float, ?string): mixed)|null $queue */
    public function __construct(private readonly Transport $transport, private readonly ?Address $from = null, private readonly ?Closure $queue = null) {}

    public function transport(): Transport
    {
        return $this->transport;
    }

    /** Sends a message, or a mailable to the given recipients. */
    public function send(Message|Mailable $mail, string ...$to): void
    {
        $message = $mail instanceof Mailable ? $this->build($mail, array_values($to)) : $mail;
        if ($message->getFrom() === null && $this->from !== null) {
            $message->from($this->from->email, $this->from->name);
        }
        $this->transport->send($message);
    }

    /** Builds and sends a plain-text message in one call. */
    public function raw(string $text, string $to, string $subject): void
    {
        $this->send((new Message())->to($to)->subject($subject)->text($text));
    }

    /** Queues a mailable; `php omnitrix queue:work` sends it. */
    public function queue(Mailable $mail, string ...$to): void
    {
        if ($this->queue === null) {
            throw new LogicException('Queued mail needs the redis configuration section (the queue)');
        }
        ($this->queue)(self::QUEUED_MAIL_JOB, ['mailable' => base64_encode(serialize($mail)), 'to' => $to], 0.0, $mail->queue);
    }

    /**
     * Sends a queued mailable (the handler of {@see QUEUED_MAIL_JOB}).
     *
     * @param array<string, mixed> $payload
     */
    public function handleQueued(array $payload): void
    {
        $data = is_string($payload['mailable'] ?? null) ? base64_decode($payload['mailable'], true) : false;
        $mail = is_string($data) ? unserialize($data) : null; // written by this application only
        if (!$mail instanceof Mailable) {
            throw new InvalidArgumentException('Invalid queued mail payload');
        }
        $to = [];
        foreach ((array) ($payload['to'] ?? []) as $address) {
            if (is_string($address)) {
                $to[] = $address;
            }
        }
        $this->send($mail, ...$to);
    }

    /** @param list<string> $to */
    private function build(Mailable $mail, array $to): Message
    {
        $message = new Message();
        foreach ($to as $address) {
            $parsed = Address::from($address);
            $message->to($parsed->email, $parsed->name);
        }
        $mail->build($message);

        return $message;
    }
}
