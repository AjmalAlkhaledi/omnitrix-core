<?php

declare(strict_types=1);

namespace Omnitrix\Mail\Transport;

use Omnitrix\Mail\Message;

/** Keeps messages in memory (tests, local development). */
final class ArrayTransport implements Transport
{
    /** @var list<Message> */
    public array $messages = [];

    public function send(Message $message): void
    {
        $this->messages[] = $message;
    }
}
