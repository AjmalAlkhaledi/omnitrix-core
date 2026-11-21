<?php

declare(strict_types=1);

namespace Omnitrix\Facades;

use LogicException;
use Omnitrix\Core\Container\Container;
use Omnitrix\Mail\Mailable;
use Omnitrix\Mail\Mailer;
use Omnitrix\Mail\MailFake;
use Omnitrix\Mail\Message;
use Omnitrix\Mail\PendingMail;

use function array_values;

/**
 * E-mail (documentation: Async & services → Mail).
 *
 * ```php
 * Mail::to('ann@example.com')->send(new WelcomeMail($user));
 * Mail::to($user->email)->queue(new InvoiceMail($invoice));
 * Mail::send((new Message())->to('ops@example.com')->subject('Alert')->text('…'));
 * $fake = Mail::fake(); … $fake->assertSent(WelcomeMail::class);
 * ```
 */
final class Mail extends Facade
{
    public static function mailer(): Mailer
    {
        return self::resolve(Mailer::class);
    }

    public static function to(string ...$addresses): PendingMail
    {
        return new PendingMail(array_values($addresses), static function (Mailable $mail, array $to, bool $queued): void {
            $fake = self::fakeInstance();
            if ($fake !== null) {
                $fake->record($mail, $to, $queued);

                return;
            }
            $queued ? self::mailer()->queue($mail, ...$to) : self::mailer()->send($mail, ...$to);
        });
    }

    public static function send(Message $message): void
    {
        $fake = self::fakeInstance();
        if ($fake !== null) {
            $fake->record($message, [], false);

            return;
        }
        self::mailer()->send($message);
    }

    public static function raw(string $text, string $to, string $subject): void
    {
        self::send((new Message())->to($to)->subject($subject)->text($text));
    }

    /** Records mail instead of sending it, in this worker (tests). */
    public static function fake(): MailFake
    {
        $container = self::container();
        if (!$container instanceof Container) {
            throw new LogicException('Mail::fake() needs the application container (inside withServices())');
        }
        $existing = self::fakeInstance();
        if ($existing !== null) {
            return $existing;
        }
        $fake = new MailFake();
        $container->instance(MailFake::class, $fake);

        return $fake;
    }

    private static function fakeInstance(): ?MailFake
    {
        $container = self::container();
        $fake = $container !== null && $container->has(MailFake::class) ? $container->get(MailFake::class) : null;

        return $fake instanceof MailFake ? $fake : null;
    }
}
