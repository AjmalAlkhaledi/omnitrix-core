<?php

declare(strict_types=1);

namespace Omnitrix\Session;

use Omnitrix\Contracts\Encrypter;
use Omnitrix\Contracts\SessionHandler;
use Omnitrix\Encryption\DecryptException;
use Omnitrix\Facades\Facade;
use Omnitrix\Http\Cookie;
use Omnitrix\Http\Middleware\MiddlewareInterface;
use Omnitrix\Http\Middleware\Next;
use Omnitrix\Http\Request;
use Omnitrix\Http\Response;
use RuntimeException;

use function is_array;
use function is_string;
use function strlen;
use function time;

/**
 * Starts the session of each request: loads it from the session cookie, makes it available
 * (`Session::…`, request attribute `session`), ages flash data, saves it and sends the cookie.
 * A session that was not used and did not exist sends no cookie.
 *
 * Drivers: `redis` (the cookie holds a random id), `cookie` (the data itself, encrypted with
 * `app.key`, up to about 4 KB) and `array` (process memory, tests).
 */
final class StartSession implements MiddlewareInterface
{
    public const string ATTRIBUTE = 'session';

    public function process(Request $request, Next $next): Response
    {
        $container = Facade::container();
        $config = $container !== null && $container->has(SessionConfig::class) ? $container->get(SessionConfig::class) : null;
        if (!$config instanceof SessionConfig) {
            throw new RuntimeException('Sessions are not configured (add a session section and, for the cookie driver, app.key)');
        }
        $handler = $container->has(SessionHandler::class) ? $container->get(SessionHandler::class) : null;
        $encrypter = $container->has(Encrypter::class) ? $container->get(Encrypter::class) : null;
        $store = $this->load($request, $config, $handler instanceof SessionHandler ? $handler : null, $encrypter instanceof Encrypter ? $encrypter : null);
        $request->setAttribute(self::ATTRIBUTE, $store);
        $request->context()->set(self::ATTRIBUTE, $store);

        $response = $next($request);

        $store->ageFlashData();
        if (!$store->isStarted()) {
            return $response;
        }
        $lifetime = $config->lifetime * 60;
        if ($config->driver === 'cookie') {
            if (!$encrypter instanceof Encrypter) {
                throw new RuntimeException('The cookie session driver needs app.key');
            }
            $value = $encrypter->encrypt(['id' => $store->id(), 'data' => $store->raw(), 'expires' => time() + $lifetime]);
            if (strlen($value) > 4000) {
                throw new RuntimeException('The session is too large for the cookie driver (about 4 KB); use the redis driver');
            }
        } else {
            if (!$handler instanceof SessionHandler) {
                throw new RuntimeException('No session handler');
            }
            $replaced = $store->replacedId();
            if ($replaced !== null) {
                $handler->destroy($replaced);
            }
            $handler->write($store->id(), $store->raw(), $lifetime);
            $value = $store->id();
        }

        return $response->cookie(new Cookie($config->cookie, $value, time() + $lifetime, $config->path, $config->domain, $config->secure, $config->httpOnly, $config->sameSite));
    }

    private function load(Request $request, SessionConfig $config, ?SessionHandler $handler, ?Encrypter $encrypter): Store
    {
        $cookie = $request->cookie($config->cookie);
        if (is_string($cookie) && $cookie !== '') {
            if ($config->driver === 'cookie' && $encrypter !== null) {
                try {
                    $payload = $encrypter->decrypt($cookie);
                } catch (DecryptException) {
                    $payload = null;
                }
                if (is_array($payload) && is_string($payload['id'] ?? null) && Store::isValidId($payload['id']) && is_array($payload['data'] ?? null) && ($payload['expires'] ?? 0) >= time()) {
                    $data = [];
                    foreach ($payload['data'] as $key => $value) {
                        $data[(string) $key] = $value;
                    }

                    return new Store($payload['id'], $data, existed: true);
                }
            } elseif ($handler !== null && Store::isValidId($cookie)) {
                $data = $handler->read($cookie);
                if ($data !== null) {
                    return new Store($cookie, $data, existed: true);
                }
            }
        }

        return new Store(Store::generateId());
    }
}
