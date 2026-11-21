<?php

declare(strict_types=1);

namespace Omnitrix\Mail;

use RuntimeException;

/** Sending failed (connection, authentication, or a rejected sender, recipient or message). */
final class MailException extends RuntimeException {}
