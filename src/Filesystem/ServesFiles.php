<?php

declare(strict_types=1);

namespace Omnitrix\Filesystem;

use Omnitrix\Http\Response;

use function addcslashes;
use function basename;
use function in_array;
use function preg_replace;
use function rawurlencode;
use function str_starts_with;

/** @internal headers for files served from a disk */
final class ServesFiles
{
    /** Types a browser would run as a page (scripts), so they are only ever downloaded. */
    private const array ACTIVE = ['text/html', 'application/xhtml+xml', 'image/svg+xml', 'application/xml', 'text/xml', 'text/javascript', 'application/javascript'];

    /** `attachment; filename="Report _.txt"; filename*=UTF-8''Report%20%C3%A9.txt` */
    public static function disposition(bool $attachment, string $name): string
    {
        $ascii = preg_replace('/[^\x20-\x7e]|["\\\\]/u', '_', $name) ?? 'file';

        return ($attachment ? 'attachment' : 'inline') . '; filename="' . addcslashes($ascii, '"') . "\"; filename*=UTF-8''" . rawurlencode($name);
    }

    public static function headers(Response $response, string $type, string $path, ?string $downloadName): Response
    {
        $attachment = $downloadName !== null || in_array($type, self::ACTIVE, true) || str_starts_with($type, 'multipart/');
        $response->header('Content-Type', $type);
        $response->header('Content-Disposition', self::disposition($attachment, $downloadName ?? basename($path)));
        $response->header('X-Content-Type-Options', 'nosniff');
        // User files never run as part of the API's origin, whatever their contents.
        $response->header('Content-Security-Policy', "default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'; sandbox");

        return $response;
    }
}
