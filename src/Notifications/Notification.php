<?php

declare(strict_types=1);

namespace Omnitrix\Notifications;

use LogicException;
use Omnitrix\Mail\Mailable;
use Omnitrix\Mail\Message;

/**
 * A notification to a user (or any notifiable): pick the channels in via() and describe the
 * message per channel.
 *
 * ```php
 * final class InvoicePaid extends Notification
 * {
 *     public function __construct(private readonly int $invoiceId) {}
 *
 *     public function via(object $notifiable): array { return ['mail', 'database']; }
 *
 *     public function toMail(object $notifiable): Message
 *     {
 *         return (new Message())->subject('Invoice paid')->text("Invoice {$this->invoiceId} was paid.");
 *     }
 *
 *     public function toArray(object $notifiable): array { return ['invoice' => $this->invoiceId]; }
 * }
 *
 * $user->notify(new InvoicePaid(42));
 * ```
 *
 * Implement {@see \Omnitrix\Events\ShouldQueue} to send it from the queue worker.
 */
abstract class Notification
{
    /** Queue name when queued (null: default). */
    public ?string $queue = null;

    /** @return list<string> channel names: mail, database */
    abstract public function via(object $notifiable): array;

    /** The e-mail for the mail channel (the recipient is added). */
    public function toMail(object $notifiable): Message|Mailable
    {
        throw new LogicException(static::class . ' has no toMail() method for the mail channel');
    }

    /**
     * The data stored by the database channel.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [];
    }
}
