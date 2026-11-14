<?php

declare(strict_types=1);

namespace Omnitrix\Http;

use InvalidArgumentException;

use function in_array;
use function preg_match;

/** Immutable Set-Cookie description. */
final readonly class Cookie
{
    public function __construct(
        public string $name,
        public string $value,
        /** Unix timestamp; 0 = session cookie. */
        public int $expires = 0,
        public string $path = '/',
        public string $domain = '',
        public bool $secure = false,
        public bool $httpOnly = true,
        public string $sameSite = 'Lax',
    ) {
        if ($name === '' || preg_match('/[=,; \t\r\n\013\014]/', $name) === 1) {
            throw new InvalidArgumentException("Invalid cookie name '{$name}'");
        }
        if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            throw new InvalidArgumentException("Invalid SameSite value '{$sameSite}'");
        }
        if ($sameSite === 'None' && !$secure) {
            throw new InvalidArgumentException('SameSite=None requires the Secure attribute');
        }
    }

    /** A cookie that instructs the client to delete `$name`. */
    public static function forget(string $name, string $path = '/', string $domain = ''): self
    {
        return new self($name, '', 1, $path, $domain);
    }
}
