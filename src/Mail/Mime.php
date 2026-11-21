<?php

declare(strict_types=1);

namespace Omnitrix\Mail;

use function addcslashes;
use function base64_encode;
use function chunk_split;
use function preg_match;
use function quoted_printable_encode;
use function rtrim;
use function str_replace;

/** @internal MIME encoding helpers */
final class Mime
{
    private function __construct() {}

    /** RFC 2047 encoded-word for non-ASCII header text. */
    public static function encodeHeader(string $text, bool $quotePlain = false): string
    {
        if (preg_match('/[^\x20-\x7e]/', $text) === 1) {
            return '=?UTF-8?B?' . base64_encode($text) . '?=';
        }
        if ($quotePlain && preg_match('/[()<>\[\]:;@\\\\,."]/', $text) === 1) {
            return '"' . addcslashes($text, '"\\') . '"';
        }

        return $text;
    }

    public static function quotedPrintable(string $text): string
    {
        // CRLF first: quoted_printable_encode() keeps CRLF line breaks and encodes a bare LF as =0A.
        return quoted_printable_encode(self::crlf($text));
    }

    public static function base64(string $data): string
    {
        return rtrim(chunk_split(base64_encode($data), 76, "\r\n"));
    }

    /** CRLF line endings throughout. */
    public static function crlf(string $text): string
    {
        return str_replace(["\r\n", "\r", "\n"], ["\n", "\n", "\r\n"], $text);
    }
}
