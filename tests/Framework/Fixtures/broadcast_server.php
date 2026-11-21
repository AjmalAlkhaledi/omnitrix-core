<?php

declare(strict_types=1);

/*
 * Broadcasting fixture: token auth, channel authorizers, the SSE stream endpoints and an endpoint
 * dispatching a broadcastable event.
 * Env: OMNITRIX_FIXTURE_PORT, OMNITRIX_TEST_DB_*, OMNITRIX_TEST_REDIS_*, FIXTURE_DB, FIXTURE_PREFIX.
 */

use Omnitrix\Application;
use Omnitrix\Auth\Authenticatable;
use Omnitrix\Auth\Authenticate;
use Omnitrix\Auth\HasApiTokens;
use Omnitrix\Broadcasting\BroadcastRoutes;
use Omnitrix\Broadcasting\Channel;
use Omnitrix\Broadcasting\PrivateChannel;
use Omnitrix\Broadcasting\ShouldBroadcast;
use Omnitrix\Contracts\Authenticatable as AuthenticatableContract;
use Omnitrix\Database\Model;
use Omnitrix\Facades\Event;
use Omnitrix\Http\Request;
use Omnitrix\Http\Response;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$env = static function (string $name, string $default): string {
    $value = getenv($name);

    return is_string($value) && $value !== '' ? $value : $default;
};

/** @property int $id @property string $name */
final class StreamUser extends Model implements AuthenticatableContract
{
    use Authenticatable;
    use HasApiTokens;

    protected static ?string $table = 'users';
}

final class OrderShipped implements ShouldBroadcast
{
    public function __construct(public int $order, public int $userId) {}

    public function broadcastOn(): array
    {
        return [new Channel('orders'), new PrivateChannel('user.' . $this->userId)];
    }
}

$app = Application::create([
    'server' => ['host' => '127.0.0.1', 'port' => (int) $env('OMNITRIX_FIXTURE_PORT', '18700'), 'workers' => 2, 'log_level' => 'error'],
    'database' => ['driver' => 'mariadb', 'host' => $env('OMNITRIX_TEST_DB_HOST', '127.0.0.1'), 'port' => (int) $env('OMNITRIX_TEST_DB_PORT', '33306'), 'database' => $env('FIXTURE_DB', 'omnitrix_test'), 'username' => $env('OMNITRIX_TEST_DB_USER', 'root'), 'password' => $env('OMNITRIX_TEST_DB_PASS', ''), 'pool' => ['min' => 0, 'max' => 8]],
    'redis' => ['host' => $env('OMNITRIX_TEST_REDIS_HOST', '127.0.0.1'), 'port' => (int) $env('OMNITRIX_TEST_REDIS_PORT', '63790'), 'prefix' => $env('FIXTURE_PREFIX', 'broadcast-test:'), 'read_timeout' => 30.0],
    'auth' => ['guards' => ['api' => ['driver' => 'token', 'provider' => 'users']], 'providers' => ['users' => ['model' => StreamUser::class]]],
    'broadcasting' => ['default' => 'redis'],
]);
$app->broadcastChannel('user.{id}', static fn(StreamUser $user, string $id): bool => (string) $user->id === $id);
$app->broadcastChannel('room.{name}', static fn(StreamUser $user, string $name): array => ['id' => $user->id, 'name' => $user->getAttribute('name')]);

BroadcastRoutes::$heartbeatSeconds = 0.5; // notice closed test connections quickly
BroadcastRoutes::register($app->router(), [Authenticate::using('api')]);
BroadcastRoutes::register($app->router(), [], '/public/stream');
$app->post('/ship/{order:\d+}/{user:\d+}', static function (Request $r): Response {
    Event::dispatch(new OrderShipped($r->routeInt('order'), $r->routeInt('user')));

    return Response::json(['shipped' => true]);
});

$app->run();
