<?php

declare(strict_types=1);

namespace Omnitrix\Contracts;

/** A user whose e-mail address must be confirmed. */
interface MustVerifyEmail
{
    public function hasVerifiedEmail(): bool;

    public function markEmailAsVerified(): bool;

    public function sendEmailVerificationNotification(): void;

    public function getEmailForVerification(): string;
}
