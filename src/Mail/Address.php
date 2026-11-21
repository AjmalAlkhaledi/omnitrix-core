<?php

declare(strict_types=1);

namespace Omnitrix\Mail;

use InvalidArgumentException;

use function filter_var;
use function is_array;
use function preg_match;
use function trim;

use const FILTER_VALIDATE_EMAIL;

/** An e-mail address with an optional display name. */
final readonly class Address
{
    public function __construct(public string $email, public string $name = '')
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n]/', $email . $name) === 1) {
            throw new InvalidArgumentException("Invalid e-mail address '{$email}'");
        }
    }

    /** @param string|array{string, string}|self $address `'a@x'`, `['a@x', 'Ann']` or an Address */
    public static function from(string|array|self $address): self
    {
        if ($address instanceof self) {
            return $address;
        }
        if (is_array($address)) {
            return new self($address[0], $address[1]);
        }
        if (preg_match('/^\s*(.*?)\s*<([^>]+)>\s*$/', $address, $m) === 1) {
            return new self(trim($m[2]), trim($m[1], " \""));
        }

        return new self(trim($address));
    }

    /** RFC 5322 form, the name encoded when it is not plain ASCII. */
    public function toString(): string
    {
        if ($this->name === '') {
            return $this->email;
        }

        return Mime::encodeHeader($this->name, quotePlain: true) . ' <' . $this->email . '>';
    }
}
