<?php

declare(strict_types=1);

namespace Omnitrix\Core\Runtime\Exception;

use ErrorException;

use function error_reporting;
use function restore_error_handler;
use function set_error_handler;

/**
 * Converts PHP warnings/notices/deprecations into {@see ErrorException}s so they surface
 * through the normal exception path instead of being silently logged. Errors suppressed with
 * `@` (not in error_reporting()) are left alone.
 */
final class ErrorHandler
{
    private bool $registered = false;

    public function register(): void
    {
        if ($this->registered) {
            return;
        }
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        $this->registered = true;
    }

    public function unregister(): void
    {
        if ($this->registered) {
            restore_error_handler();
            $this->registered = false;
        }
    }

    public function isRegistered(): bool
    {
        return $this->registered;
    }
}
