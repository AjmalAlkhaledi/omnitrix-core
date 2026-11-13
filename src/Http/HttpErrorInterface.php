<?php

declare(strict_types=1);

namespace Omnitrix\Core\Http;

use Throwable;

/**
 * An error that maps directly to an HTTP response.
 *
 * The transport layer renders any throwable implementing this interface with its status,
 * headers and details; the message is considered safe to show to clients. Higher layers
 * define their own exception hierarchies on top of it.
 */
interface HttpErrorInterface extends Throwable
{
    public function status(): int;

    /** @return array<string, string> extra response headers (e.g. Retry-After, Allow) */
    public function headers(): array;

    /** @return array<string, mixed> safe, structured data merged into the JSON error body */
    public function details(): array;
}
