# Background Jobs

An optional Redis-backed job queue. HTTP requests are never routed through it; use it for work
that should happen after the response (emails, webhooks, reports) or must survive restarts.
It needs the `redis` configuration section ([redis.md](redis.md)).

## Dispatching

```php
<?php

use Omnitrix\Facades\Queue;

$jobId = Queue::dispatch('emails.send', ['user_id' => 10]);
Queue::dispatch('reports.render', ['month' => '2026-10'], delay: 300, queue: 'reports', maxAttempts: 5);
$stats = Queue::stats(); // ['ready' => …, 'delayed' => …, 'processing' => …, 'dead' => …]
```

## Running a worker

A worker is a separate long-running process, for example `bin/worker.php`:

```php
<?php

declare(strict_types=1);

use Omnitrix\Config\Env;
use Omnitrix\Core\Queue\Job;
use Omnitrix\Queue\Worker;

require __DIR__ . '/../vendor/autoload.php';

$config = ['redis' => ['host' => Env::get('REDIS_HOST', '127.0.0.1') ?? '127.0.0.1', 'port' => Env::int('REDIS_PORT', 6379)]];

$worker = new Worker($config, queue: 'default', concurrency: 8, jobTimeout: 60.0);

$worker->handle('emails.send', static function (array $payload, Job $job): void {
    // Idempotent work. $job->id, $job->attempts and $job->maxAttempts are available.
});

exit($worker->run());   // returns 0 after SIGTERM/SIGINT once in-flight jobs finished
```

Handlers run in coroutines with OpenSwoole runtime hooks enabled, so PDO, phpredis, sockets
and `Async` cooperate as they do in HTTP handlers. Run several worker processes (systemd
template units, Kubernetes replicas) to scale out.

| Constructor argument | Default | Meaning |
|---|---|---|
| `$config` | — | application configuration containing the `redis` section |
| `queue` | `default` | queue name to consume |
| `concurrency` | 4 | jobs processed at the same time in this process |
| `jobTimeout` | 60.0 | seconds before a running job is treated as failed |
| `backoffBase` | 1.0 | base delay of the exponential retry backoff, in seconds |
| `logger` | JSON to stderr | any PSR-3 logger |

## Semantics

- **Durable**: jobs are Redis list entries; they exist before any worker runs.
- **At-least-once**: reserving moves a job atomically from `ready` to the worker's
  `processing` list (`BLMOVE`). A worker that crashes leaves its jobs there; once its
  heartbeat key (30 s TTL) expires, any running worker moves them back to `ready`.
  Handlers must be idempotent.
- **Retries**: a throwing handler (or one exceeding `jobTimeout`) is re-scheduled with
  exponential backoff (`backoffBase × 2^(attempt−1)`, capped, ≤ 10 % jitter) until
  `maxAttempts`; then the job goes to the dead-letter list with its last error.
- **Unknown job names** and **malformed messages** go straight to the dead-letter list.
- **Delayed jobs** are promoted by an atomic Lua script on every reserve.
- **Graceful shutdown**: SIGTERM/SIGINT stop reserving; in-flight jobs finish; `run()` returns.
- Payloads are JSON (no `unserialize()`).

## Observability

`$worker->stats()` (after `run()` returns) → processed, failed, retried, buried, recovered.
`Queue::stats($queue)` → ready, delayed, processing, dead.
