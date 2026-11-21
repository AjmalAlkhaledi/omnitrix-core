<?php

declare(strict_types=1);

/*
 * Maintenance fixture project: bootstrap of a 2-worker server whose maintenance state is in
 * Redis. Env: OMNITRIX_FIXTURE_PORT, FIXTURE_PREFIX, OMNITRIX_TEST_REDIS_*.
 */

use Omnitrix\Application;
use Omnitrix\Http\Response;

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

$env = static function (string $name, string $default): string {
    $value = getenv($name);

    return is_string($value) && $value !== '' ? $value : $default;
};

$app = Application::create([
    'server' => ['host' => '127.0.0.1', 'port' => (int) $env('OMNITRIX_FIXTURE_PORT', '18730'), 'workers' => 2, 'log_level' => 'error'],
    'redis' => ['host' => $env('OMNITRIX_TEST_REDIS_HOST', '127.0.0.1'), 'port' => (int) $env('OMNITRIX_TEST_REDIS_PORT', '63790'), 'prefix' => $env('FIXTURE_PREFIX', 'maintenance-test:')],
    'app' => ['maintenance' => ['driver' => 'redis']],
]);
$app->health();
$app->get('/api/ping', static fn(): Response => Response::json(['pong' => getmypid()]));

return $app;
