<?php

declare(strict_types=1);

namespace Omnitrix\Broadcasting;

use Omnitrix\Auth\AuthManager;
use Omnitrix\Contracts\Authenticatable;
use Omnitrix\Core\Concurrency\CancellationToken;
use Omnitrix\Facades\Facade;
use Omnitrix\Http\Middleware\MiddlewareInterface;
use Omnitrix\Http\Request;
use Omnitrix\Http\Response;
use Omnitrix\Http\Stream;
use Omnitrix\Routing\Router;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel as Queue;
use Redis;
use RedisCluster;
use Throwable;

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function str_starts_with;
use function strlen;
use function substr;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Server-Sent Events endpoint for browsers and other clients:
 *
 * ```php
 * BroadcastRoutes::register($router, [Authenticate::using('api')]); // GET /broadcasting/stream?channels=orders,private-user.5
 * ```
 *
 * ```js
 * const events = new EventSource('/broadcasting/stream?channels=orders,private-user.5');
 * events.addEventListener('OrderShipped', e => console.log(JSON.parse(e.data)));
 * ```
 *
 * Each connection subscribes to Redis for its channels (needs the `redis` broadcaster) and
 * receives `event: {name}` / `data: {"channel", "data"}` messages; a comment line every
 * {@see $heartbeatSeconds} keeps proxies from closing it. Private and presence channels are authorized with the channel
 * callbacks; presence channels also send `presence.members` on connect and `presence.joined` /
 * `presence.left` to the others.
 */
final class BroadcastRoutes
{
    private const int MAX_CHANNELS = 20;

    /**
     * Seconds between keep-alive comments on an idle stream. A disconnected client is noticed at
     * the next write, so presence `left` events arrive within this interval.
     */
    public static float $heartbeatSeconds = 15.0;

    private function __construct() {}

    /** @param list<MiddlewareInterface> $middleware usually Authenticate::using(...) for private channels */
    public static function register(Router $router, array $middleware = [], string $path = '/broadcasting/stream'): void
    {
        $route = $router->get($path, static fn(Request $request): Response => self::stream($request))->name('broadcasting.stream');
        if ($middleware !== []) {
            $route->middleware(...$middleware);
        }
    }

    public static function stream(Request $request): Response
    {
        $container = Facade::container();
        $manager = $container !== null && $container->has(BroadcastManager::class) ? $container->get(BroadcastManager::class) : null;
        $broadcaster = $manager instanceof BroadcastManager ? $manager->broadcaster() : null;
        if (!$manager instanceof BroadcastManager || !$broadcaster instanceof RedisBroadcaster) {
            return Response::json(['error' => ['status' => 501, 'message' => 'Streaming needs the redis broadcaster']], 501);
        }
        $requested = $request->queryParam('channels');
        $channels = array_values(array_unique(array_filter(array_map('trim', explode(',', is_string($requested) ? $requested : '')), static fn(string $c): bool => $c !== '')));
        if ($channels === [] || count($channels) > self::MAX_CHANNELS) {
            return Response::json(['error' => ['status' => 422, 'message' => 'Give 1 to ' . self::MAX_CHANNELS . ' channels in ?channels=']], 422);
        }
        $auth = $container->has(AuthManager::class) ? $container->get(AuthManager::class) : null;
        $user = $auth instanceof AuthManager ? $auth->guard()->user() : null;
        $members = [];
        foreach ($channels as $channel) {
            $granted = $manager->authorize($user, $channel);
            if ($granted === false) {
                return Response::json(['error' => ['status' => 403, 'message' => "Not allowed to listen to {$channel}"]], 403);
            }
            if (is_array($granted) && str_starts_with($channel, PresenceChannel::PREFIX)) {
                $members[$channel] = $granted;
            }
        }

        return Response::stream(static function (Stream $stream) use ($broadcaster, $channels, $members, $user): void {
            self::run($stream, $broadcaster, $channels, $members, $user);
        }, 200, 'text/event-stream')
            ->header('Cache-Control', 'no-cache')
            ->header('X-Accel-Buffering', 'no');
    }

    /**
     * @param list<string>                         $channels
     * @param array<string, array<string, mixed>>  $members presence channel => this user's member info
     */
    private static function run(Stream $stream, RedisBroadcaster $broadcaster, array $channels, array $members, ?Authenticatable $user): void
    {
        $redis = $broadcaster->redis();
        $queue = new Queue(256);
        $token = new CancellationToken();
        $subscribed = new Queue(1);
        Coroutine::create(static function () use ($redis, $channels, $queue, $token, $subscribed): void {
            try {
                $subscribed->push(true);
                $redis->subscribe(array_map(static fn(string $c): string => RedisBroadcaster::PREFIX . $c, $channels), static function (string $channel, string $message) use ($queue, $token): bool {
                    if ($token->isCancelled()) {
                        return false;
                    }
                    $queue->push([substr($channel, strlen(RedisBroadcaster::PREFIX)), $message], 1.0);

                    return true;
                }, $token);
            } catch (Throwable) {
                // Redis went away: the stream ends and the client reconnects (EventSource retries)
            } finally {
                $queue->close();
            }
        });
        $subscribed->pop(1.0);
        $memberId = $user?->getAuthIdentifier();
        try {
            $stream->write("retry: 3000\n\n");
            foreach ($members as $channel => $info) {
                $key = 'presence:' . $channel;
                $redis->hset($key, (string) $memberId, (string) json_encode($info));
                $connections = $redis->withConnection(static fn(Redis|RedisCluster $r): mixed => $r->hIncrBy('presence-connections:' . $channel, (string) $memberId, 1));
                if ($connections === 1) {
                    $broadcaster->publish([$channel], 'presence.joined', $info);
                }
                $all = array_values(array_filter(array_map(static fn(string $json): mixed => json_decode($json, true), $redis->hgetall($key)), 'is_array'));
                $stream->write(self::message('presence.members', $channel, $all));
            }
            while (!$stream->clientGone()) {
                $item = $queue->pop(self::$heartbeatSeconds);
                if ($item === false) {
                    if ($queue->errCode === -2 /* closed */ || $token->isCancelled()) {
                        break;
                    }
                    if (!$stream->write(": ping\n\n")) {
                        break;
                    }
                    continue;
                }
                [$channel, $message] = is_array($item) ? $item : ['', ''];
                $decoded = is_string($message) ? json_decode($message, true) : null;
                if (!is_array($decoded) || !is_string($decoded['event'] ?? null)) {
                    continue;
                }
                if (!$stream->write(self::message($decoded['event'], is_string($channel) ? $channel : '', $decoded['data'] ?? null))) {
                    break;
                }
            }
        } finally {
            $token->cancel('stream closed');
            foreach ($members as $channel => $info) {
                try {
                    $left = $redis->withConnection(static fn(Redis|RedisCluster $r): mixed => $r->hIncrBy('presence-connections:' . $channel, (string) $memberId, -1));
                    if (is_int($left) && $left <= 0) {
                        $redis->withConnection(static fn(Redis|RedisCluster $r): mixed => $r->hDel('presence:' . $channel, (string) $memberId));
                        $redis->withConnection(static fn(Redis|RedisCluster $r): mixed => $r->hDel('presence-connections:' . $channel, (string) $memberId));
                        $broadcaster->publish([$channel], 'presence.left', $info);
                    }
                } catch (Throwable) {
                    // best effort
                }
            }
        }
    }

    private static function message(string $event, string $channel, mixed $data): string
    {
        return 'event: ' . $event . "\n" . 'data: ' . json_encode(['channel' => $channel, 'data' => $data], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
    }
}
