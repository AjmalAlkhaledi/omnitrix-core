<?php

declare(strict_types=1);

namespace Omnitrix\Filesystem;

use InvalidArgumentException;
use Omnitrix\Facades\Storage;
use Omnitrix\Http\Request;
use Omnitrix\Http\Response;
use Omnitrix\Routing\Router;
use Omnitrix\Routing\ValidateSignature;

use function is_string;

/**
 * Routes that serve files from local disks:
 *
 * - `GET {prefix}/{path}` serves the `public` disk (what `Storage::disk('public')->url()` points to);
 * - `GET /storage-temporary/{disk}/{path}` serves `temporaryUrl()` links of any local disk,
 *   only with a valid, unexpired signature.
 */
final class StorageRoutes
{
    public const string TEMPORARY = 'omnitrix.storage.temporary';

    public static function register(Router $router, string $publicDisk = 'public', string $prefix = '/storage'): void
    {
        $router->get(rtrim($prefix, '/') . '/{path:.+}', static fn(Request $request): Response => self::serve($publicDisk, $request->route('path')))->name('omnitrix.storage.public');
        $router->get('/storage-temporary/{disk:[A-Za-z0-9_.-]+}/{path:.+}', static fn(Request $request): Response => self::serve($request->route('disk'), $request->route('path')))
            ->middleware(new ValidateSignature())
            ->name(self::TEMPORARY);
    }

    private static function serve(mixed $disk, mixed $path): Response
    {
        if (!is_string($disk) || !is_string($path)) {
            return Response::json(['error' => 'Not Found'], 404);
        }
        try {
            return Storage::disk($disk)->response(rawurldecode($path));
        } catch (FileNotFoundException | InvalidArgumentException) {
            return Response::json(['error' => 'Not Found'], 404);
        }
    }
}
