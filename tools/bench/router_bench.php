<?php

declare(strict_types=1);

/*
 * Dispatch micro-benchmark: Omnitrix Router (FastRoute GroupCountBased + RouteMatch) versus
 * raw FastRoute, and the full HttpKernel::handle() path. Run: bin/php tools/bench/router_bench.php
 */

use FastRoute\DataGenerator\GroupCountBased as Generator;
use FastRoute\Dispatcher\GroupCountBased as Dispatcher;
use FastRoute\RouteCollector;
use FastRoute\RouteParser\Std;
use Omnitrix\Application;
use Omnitrix\Http\Request;
use Omnitrix\Http\Response;
use Omnitrix\Core\Http\Server\IncomingRequest;
use Omnitrix\Routing\Router;
use Omnitrix\Core\Runtime\Context\RequestContext;
use OpenSwoole\Http\Request as SwooleRequest;
use Psr\Log\NullLogger;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$iterations = (int) ($argv[1] ?? 200_000);
$define = static function (callable $add): void {
    for ($i = 0; $i < 50; $i++) {
        $add('GET', "/static/{$i}");
        $add('GET', "/dyn/{$i}/{id:\\d+}");
        $add('POST', "/dyn/{$i}/{id:\\d+}/items/{item}");
    }
};

$collector = new RouteCollector(new Std(), new Generator());
$define(static fn(string $m, string $p) => $collector->addRoute($m, $p, 'h'));
$raw = new Dispatcher($collector->getData());

$router = new Router();
$define(static fn(string $m, string $p) => $router->map([$m], $p, static fn(): Response => Response::noContent()));
$router->compile();

$app = Application::create(['server' => ['workers' => 1]], new NullLogger());
$define(static fn(string $m, string $p) => $app->map([$m], $p, static fn(Request $r): array => ['ok' => $r->routeParams()]));
$app->middleware(static fn(Request $r, callable $next): Response => $next($r));
$kernel = $app->makeKernel();
$swoole = SwooleRequest::create();
if (!$swoole instanceof SwooleRequest) {
    exit(1);
}
$swoole->parse("POST /dyn/25/123/items/abc HTTP/1.1\r\nHost: h\r\n\r\n");
$incoming = new IncomingRequest($swoole);

$bench = static function (string $label, callable $fn) use ($iterations): void {
    $fn();
    $t = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $fn();
    }
    $s = (hrtime(true) - $t) / 1e9;
    printf("%-52s %8.0f ops/s  %6.2f µs/op\n", $label, $iterations / $s, $s / $iterations * 1e6);
};

$bench('raw FastRoute dispatch (static)', static fn() => $raw->dispatch('GET', '/static/25'));
$bench('raw FastRoute dispatch (dynamic, 3 params)', static fn() => $raw->dispatch('POST', '/dyn/25/123/items/abc'));
$bench('Omnitrix Router::dispatch (static)', static fn() => $router->dispatch('GET', '/static/25'));
$bench('Omnitrix Router::dispatch (dynamic, 3 params)', static fn() => $router->dispatch('POST', '/dyn/25/123/items/abc'));
$bench('HttpKernel::handle (dynamic + 1 middleware + JSON)', static fn() => $kernel->handle(new Request($incoming, RequestContext::create())));
printf("peak memory %.1f MB\n", memory_get_peak_usage(true) / 1048576);
