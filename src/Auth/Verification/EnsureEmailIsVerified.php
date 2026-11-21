<?php

declare(strict_types=1);

namespace Omnitrix\Auth\Verification;

use Omnitrix\Contracts\MustVerifyEmail;
use Omnitrix\Facades\Auth;
use Omnitrix\Http\Middleware\MiddlewareInterface;
use Omnitrix\Http\Middleware\Next;
use Omnitrix\Http\Request;
use Omnitrix\Http\Response;

/** Answers 403 to users who have not confirmed their e-mail address (after Authenticate). */
final class EnsureEmailIsVerified implements MiddlewareInterface
{
    public function __construct(private readonly ?string $guard = null) {}

    public function process(Request $request, Next $next): Response
    {
        $user = Auth::guard($this->guard)->user();
        if ($user instanceof MustVerifyEmail && !$user->hasVerifiedEmail()) {
            return Response::json(['error' => ['status' => 403, 'message' => 'Your e-mail address is not verified'], 'request_id' => $request->id()], 403);
        }

        return $next($request);
    }
}
