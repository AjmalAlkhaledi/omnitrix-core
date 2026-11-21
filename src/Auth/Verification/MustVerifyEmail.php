<?php

declare(strict_types=1);

namespace Omnitrix\Auth\Verification;

use DateTimeImmutable;
use Omnitrix\Facades\Notification;

use function is_string;

/**
 * Implements {@see \Omnitrix\Contracts\MustVerifyEmail} for models with `email` and
 * `email_verified_at` columns.
 */
trait MustVerifyEmail
{
    public function hasVerifiedEmail(): bool
    {
        return $this->getAttribute('email_verified_at') !== null;
    }

    public function markEmailAsVerified(): bool
    {
        $this->setAttribute('email_verified_at', new DateTimeImmutable());

        return $this->save();
    }

    public function sendEmailVerificationNotification(): void
    {
        Notification::send($this, new VerifyEmail());
    }

    public function getEmailForVerification(): string
    {
        $email = $this->getAttribute('email');

        return is_string($email) ? $email : '';
    }
}
