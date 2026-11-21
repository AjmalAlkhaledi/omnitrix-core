<?php

declare(strict_types=1);

namespace Omnitrix\Contracts;

use Omnitrix\Mail\Mailable;
use Omnitrix\Mail\Message;

/** Sends e-mail. */
interface Mailer
{
    public function send(Message|Mailable $mail, string ...$to): void;

    public function queue(Mailable $mail, string ...$to): void;
}
