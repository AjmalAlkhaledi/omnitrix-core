<?php

declare(strict_types=1);

namespace Omnitrix\Mail;

/**
 * A reusable e-mail: put what it needs in the constructor and write the message in build().
 *
 * ```php
 * final class WelcomeMail extends Mailable
 * {
 *     public function __construct(private readonly string $name) {}
 *
 *     public function build(Message $message): void
 *     {
 *         $message->subject('Welcome')->text("Hi {$this->name}")->html("<p>Hi {$this->name}</p>");
 *     }
 * }
 *
 * Mail::to('ann@example.com')->send(new WelcomeMail('Ann'));
 * Mail::to('ann@example.com')->queue(new WelcomeMail('Ann')); // sent by queue:work
 * ```
 *
 * Queued mailables are serialized with the job: keep their properties small and serializable.
 */
abstract class Mailable
{
    /** Queue for queue(); null uses the default. */
    public ?string $queue = null;

    abstract public function build(Message $message): void;
}
