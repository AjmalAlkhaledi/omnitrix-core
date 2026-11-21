<?php

declare(strict_types=1);

namespace Omnitrix\Routing;

use Omnitrix\Facades\Facade;
use Omnitrix\Http\Middleware\MiddlewareInterface;
use Omnitrix\Http\Middleware\Next;
use Omnitrix\Http\Request;
use Omnitrix\Http\Response;

/** Answers 403 unless the request URL carries a valid, unexpired signature ({@see UrlGenerator::signedRoute()}). */
final class ValidateSignature implements MiddlewareInterface
{
    public function process(Request $request, Next $next): Response
    {
        $url = Facade::container()?->get(UrlGenerator::class);
        if (!$url instanceof UrlGenerator || !$url->hasCorrectSignature($request)) {
            return Response::json(['error' => ['status' => 403, 'message' => 'Invalid signature'], 'request_id' => $request->id()], 403);
        }
        if ($url->signatureHasExpired($request)) {
            return Response::json(['error' => ['status' => 403, 'message' => 'The link has expired'], 'request_id' => $request->id()], 403);
        }

        return $next($request);
    }
}
