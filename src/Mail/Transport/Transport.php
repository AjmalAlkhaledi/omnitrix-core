<?php

declare(strict_types=1);

namespace Omnitrix\Mail\Transport;

use Omnitrix\Mail\Message;

/** Delivers a message. */
interface Transport
{
    /** @throws \Omnitrix\Mail\MailException */
    public function send(Message $message): void;
}
