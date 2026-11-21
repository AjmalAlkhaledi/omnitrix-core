<?php

declare(strict_types=1);

namespace Omnitrix\Http\Middleware;

use Omnitrix\Contracts\Encrypter;
use Omnitrix\Encryption\DecryptException;
use Omnitrix\Facades\Facade;
use Omnitrix\Http\Request;
use Omnitrix\Http\Response;
use RuntimeException;

use function in_array;

/**
 * Encrypts the cookies the application sets and decrypts those it receives (with `app.key`),
 * so clients can neither read nor change them. Cookies that do not decrypt are dropped.
 * `XSRF-TOKEN` and the names in `$except` are left as they are.
 */
final class EncryptCookies implements MiddlewareInterface
{
    /** @param list<string> $except */
    public function __construct(private readonly array $except = []) {}

    public function process(Request $request, Next $next): Response
    {
        $container = Facade::container();
        $encrypter = $container !== null && $container->has(Encrypter::class) ? $container->get(Encrypter::class) : null;
        if (!$encrypter instanceof Encrypter) {
            throw new RuntimeException('EncryptCookies needs app.key (php omnitrix key:generate)');
        }
        $plain = [];
        foreach ($request->cookies() as $name => $value) {
            if ($this->skip($name)) {
                $plain[$name] = $value;
                continue;
            }
            try {
                $plain[$name] = $encrypter->decryptString($value);
            } catch (DecryptException) {
                // forged, from another key, or set before encryption: ignored
            }
        }
        $request->replaceCookies($plain);
        $response = $next($request);
        $cookies = [];
        foreach ($response->getCookies() as $cookie) {
            $cookies[] = $this->skip($cookie->name) || $cookie->value === '' ? $cookie : $cookie->withValue($encrypter->encryptString($cookie->value));
        }

        return $response->replaceCookies($cookies);
    }

    private function skip(string $name): bool
    {
        return $name === 'XSRF-TOKEN' || in_array($name, $this->except, true);
    }
}
