<?php

declare(strict_types=1);

namespace Omnitrix\Auth\Passwords;

use Omnitrix\Mail\Message;
use Omnitrix\Notifications\Notification;

use function htmlspecialchars;
use function rawurlencode;
use function str_replace;

use const ENT_QUOTES;

/** The e-mail with the password reset link (`auth.passwords.url` with `{token}` and `{email}`). */
class ResetPassword extends Notification
{
    public function __construct(public readonly string $token, public readonly string $email, public readonly string $urlTemplate, public readonly int $expireMinutes) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function url(): string
    {
        return str_replace(['{token}', '{email}'], [rawurlencode($this->token), rawurlencode($this->email)], $this->urlTemplate);
    }

    public function toMail(object $notifiable): Message
    {
        $url = $this->url();

        return (new Message())
            ->subject('Reset your password')
            ->text("Someone asked to reset the password of your account. Open this link to choose a new one:\n\n{$url}\n\nThe link expires in {$this->expireMinutes} minutes. If it was not you, ignore this e-mail; your password stays the same.")
            ->html('<p>Someone asked to reset the password of your account.</p><p><a href="' . htmlspecialchars($url, ENT_QUOTES) . '">Choose a new password</a></p><p>The link expires in ' . $this->expireMinutes . ' minutes. If it was not you, ignore this e-mail; your password stays the same.</p>');
    }
}
