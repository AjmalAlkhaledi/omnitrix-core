<?php

declare(strict_types=1);

namespace Omnitrix\Auth\Verification;

use LogicException;
use Omnitrix\Contracts\Authenticatable;
use Omnitrix\Contracts\MustVerifyEmail;
use Omnitrix\Facades\URL;
use Omnitrix\Mail\Message;
use Omnitrix\Notifications\Notification;

use function htmlspecialchars;
use function sha1;

use const ENT_QUOTES;

/**
 * The e-mail with the verification link: a signed URL to the `verification.verify` route that
 * expires after {@see self::$expireMinutes}. Subclass it (and send it yourself) to change the
 * wording.
 */
class VerifyEmail extends Notification
{
    public static int $expireMinutes = 60;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): Message
    {
        $url = self::verificationUrl($notifiable);

        return (new Message())
            ->subject('Verify your e-mail address')
            ->text("Please confirm your e-mail address by opening this link:\n\n{$url}\n\nThe link expires in " . self::$expireMinutes . " minutes. If you did not create an account, ignore this e-mail.")
            ->html('<p>Please confirm your e-mail address.</p><p><a href="' . htmlspecialchars($url, ENT_QUOTES) . '">Verify e-mail address</a></p><p>The link expires in ' . self::$expireMinutes . ' minutes. If you did not create an account, ignore this e-mail.</p>');
    }

    public static function verificationUrl(object $notifiable): string
    {
        if (!$notifiable instanceof MustVerifyEmail || !$notifiable instanceof Authenticatable) {
            throw new LogicException('E-mail verification needs a user implementing MustVerifyEmail and Authenticatable');
        }
        $id = $notifiable->getAuthIdentifier();

        return URL::temporarySignedRoute('verification.verify', self::$expireMinutes * 60, [
            'id' => $id === null ? '' : (string) $id,
            'hash' => sha1($notifiable->getEmailForVerification()),
        ]);
    }
}
