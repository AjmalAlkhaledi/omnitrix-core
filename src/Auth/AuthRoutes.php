<?php

declare(strict_types=1);

namespace Omnitrix\Auth;

use Omnitrix\Auth\Events\Verified;
use Omnitrix\Contracts\EventDispatcher;
use Omnitrix\Contracts\MustVerifyEmail;
use Omnitrix\Database\Model;
use Omnitrix\Facades\Auth;
use Omnitrix\Facades\Facade;
use Omnitrix\Facades\Password;
use Omnitrix\Facades\RateLimiter;
use Omnitrix\Http\Request;
use Omnitrix\Http\Response;
use Omnitrix\Routing\Router;
use Omnitrix\Routing\ValidateSignature;

use function hash_equals;
use function is_string;
use function sha1;

/**
 * Ready-made JSON endpoints for e-mail verification and password reset:
 *
 * ```php
 * AuthRoutes::verification($router, guard: 'api');   // GET email/verify/{id}/{hash}, POST email/verification-notification
 * AuthRoutes::passwordReset($router);                // POST forgot-password, POST reset-password
 * ```
 */
final class AuthRoutes
{
    private function __construct() {}

    public static function verification(Router $router, string $guard = 'api', string $prefix = '/email'): void
    {
        // The signed link proves the request; no login is needed to open it from a mail client.
        $router->get($prefix . '/verify/{id}/{hash}', static function (Request $request) use ($guard): Response {
            $id = $request->route('id');
            $hash = $request->route('hash');
            $model = Auth::manager()->providerModel($guard);
            $user = is_string($id) ? $model::find($id) : null;
            if (!$user instanceof MustVerifyEmail || !is_string($hash) || !hash_equals(sha1($user->getEmailForVerification()), $hash)) {
                return Response::json(['error' => ['status' => 403, 'message' => 'Invalid verification link']], 403);
            }
            if (!$user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
                $events = Facade::container()?->get(EventDispatcher::class);
                if ($events instanceof EventDispatcher) {
                    $events->dispatch(new Verified($user));
                }
            }

            return Response::json(['verified' => true]);
        })->name('verification.verify')->middleware(new ValidateSignature());

        $router->post($prefix . '/verification-notification', static function (Request $request): Response {
            $user = Auth::user();
            if (!$user instanceof MustVerifyEmail) {
                return Response::json(['error' => ['status' => 400, 'message' => 'This account has no e-mail to verify']], 400);
            }
            if ($user->hasVerifiedEmail()) {
                return Response::json(['verified' => true]);
            }
            $key = 'verify:' . $user->getEmailForVerification();
            if (RateLimiter::tooManyAttempts($key, 6, 60)) {
                return Response::json(['error' => ['status' => 429, 'message' => 'Too many requests']], 429)->header('Retry-After', (string) RateLimiter::availableIn($key, 60));
            }
            RateLimiter::hit($key, 6, 60);
            $user->sendEmailVerificationNotification();

            return Response::json(['sent' => true], 202);
        })->name('verification.send')->middleware(Authenticate::using($guard));
    }

    public static function passwordReset(Router $router, string $prefix = ''): void
    {
        $router->post($prefix . '/forgot-password', static function (Request $request): Response {
            $data = $request->validate(['email' => 'required|email|max:255']);
            $status = Password::sendResetLink(['email' => $data['email']]);
            if ($status === Password::RESET_THROTTLED) {
                return Response::json(['error' => ['status' => 429, 'message' => 'Please wait before asking for another link']], 429);
            }

            // The same answer whether or not the address has an account (no account discovery).
            return Response::json(['message' => 'If the address has an account, a reset link has been sent'], 202);
        })->name('password.email');

        $router->post($prefix . '/reset-password', static function (Request $request): Response {
            $data = $request->validate(['email' => 'required|email|max:255', 'token' => 'required|string|max:200', 'password' => 'required|string|min:8|max:200']);
            $status = Password::reset($data, static function (\Omnitrix\Contracts\Authenticatable $user, string $password): void {
                if ($user instanceof Model) {
                    $user->setAttribute('password', $password); // the 'hashed' cast hashes it
                    $user->setRememberToken(null);
                    $user->save();
                }
            });
            if ($status !== Password::PASSWORD_RESET) {
                return Response::json(['error' => ['status' => 422, 'message' => 'This reset link is invalid or has expired']], 422);
            }

            return Response::json(['reset' => true]);
        })->name('password.update');
    }
}
