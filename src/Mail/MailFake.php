<?php

declare(strict_types=1);

namespace Omnitrix\Mail;

use PHPUnit\Framework\Assert;

use function count;
use function in_array;

/** Test double installed by `Mail::fake()`: records mailables and messages instead of sending. */
final class MailFake
{
    /** @var list<array{Message|Mailable, list<string>, bool}> mail, recipients, queued */
    private array $mail = [];

    /** @param list<string> $to */
    public function record(Message|Mailable $mail, array $to, bool $queued): void
    {
        $this->mail[] = [$mail, $to, $queued];
    }

    /**
     * @param class-string<Mailable>             $mailable
     * @param (callable(Mailable, list<string>): bool)|null $filter
     *
     * @return list<Mailable>
     */
    public function sent(string $mailable, ?callable $filter = null, ?bool $queued = null): array
    {
        $matches = [];
        foreach ($this->mail as [$mail, $to, $wasQueued]) {
            if ($mail instanceof $mailable && ($queued === null || $queued === $wasQueued) && ($filter === null || $filter($mail, $to))) {
                $matches[] = $mail;
            }
        }

        return $matches;
    }

    /** @param class-string<Mailable> $mailable */
    public function assertSent(string $mailable, ?callable $filter = null, ?int $times = null): void
    {
        $count = count($this->sent($mailable, $filter, queued: false));
        Assert::assertTrue($times === null ? $count > 0 : $count === $times, "{$mailable} was sent {$count} time(s)");
    }

    /** @param class-string<Mailable> $mailable */
    public function assertQueued(string $mailable, ?callable $filter = null): void
    {
        Assert::assertNotSame([], $this->sent($mailable, $filter, queued: true), "{$mailable} was not queued");
    }

    /** @param class-string<Mailable> $mailable */
    public function assertNotSent(string $mailable): void
    {
        Assert::assertSame([], $this->sent($mailable), "{$mailable} was sent");
    }

    /** Whether a mailable was sent or queued to `$email`. */
    public function hasSentTo(string $email): bool
    {
        foreach ($this->mail as [$mail, $to]) {
            if (in_array($email, $to, true)) {
                return true;
            }
            if ($mail instanceof Message) {
                foreach ($mail->recipients() as $address) {
                    if ($address->email === $email) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    public function assertNothingSent(): void
    {
        Assert::assertSame([], $this->mail, 'Mail was sent or queued');
    }
}
