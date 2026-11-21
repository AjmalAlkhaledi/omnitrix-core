<?php

declare(strict_types=1);

namespace Omnitrix\Mail;

use Closure;

/** `Mail::to(...)->send($mailable)` / `->queue($mailable)`. */
final readonly class PendingMail
{
    /**
     * @param list<string>                                              $to
     * @param Closure(Mailable, list<string>, bool): void              $deliver
     */
    public function __construct(private array $to, private Closure $deliver) {}

    public function send(Mailable $mail): void
    {
        ($this->deliver)($mail, $this->to, false);
    }

    public function queue(Mailable $mail): void
    {
        ($this->deliver)($mail, $this->to, true);
    }
}
