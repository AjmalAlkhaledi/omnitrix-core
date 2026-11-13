<?php

declare(strict_types=1);

/*
 * Queue worker fixture built only with the public API.
 * Env: OMNITRIX_TEST_REDIS_HOST/PORT, QUEUE_NAME.
 */

use Omnitrix\Config\Env;
use Omnitrix\Facades\Async;
use Omnitrix\Queue\Worker;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$config = ['redis' => ['host' => Env::get('OMNITRIX_TEST_REDIS_HOST', '127.0.0.1') ?? '127.0.0.1', 'port' => Env::int('OMNITRIX_TEST_REDIS_PORT', 63790), 'prefix' => 'omnitrix-worker-test:']];
$worker = new Worker($config, queue: Env::get('QUEUE_NAME', 'default') ?? 'default', concurrency: 2, jobTimeout: 5.0, backoffBase: 0.1);
$worker->handle('greet', static function (array $payload): void {
    $redis = new Redis();
    $redis->connect(Env::get('OMNITRIX_TEST_REDIS_HOST', '127.0.0.1') ?? '127.0.0.1', Env::int('OMNITRIX_TEST_REDIS_PORT', 63790));
    $redis->setOption(Redis::OPT_PREFIX, 'omnitrix-worker-test:');
    Async::sleep(0.05);
    $redis->rPush('greetings', 'hello ' . (is_string($payload['name'] ?? null) ? $payload['name'] : '?'));
});

$code = $worker->run();
fwrite(STDERR, 'worker exited ' . json_encode($worker->stats()) . "\n");
exit($code);
