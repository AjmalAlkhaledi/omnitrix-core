<?php

declare(strict_types=1);

namespace Omnitrix\Mail\Transport;

use Omnitrix\Mail\Address;
use Omnitrix\Mail\Message;
use Psr\Log\LoggerInterface;

use function array_map;

/** Writes messages to the log instead of sending them (local development). */
final class LogTransport implements Transport
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function send(Message $message): void
    {
        $this->logger->info('mail', [
            'to' => array_map(static fn(Address $a): string => $a->email, $message->recipients()),
            'subject' => $message->getSubject(),
            'text' => $message->getText(),
        ]);
    }
}
