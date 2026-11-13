<?php

declare(strict_types=1);

/*
 * Controlled upstream for HTTP client tests and benchmarks.
 * Usage: php upstream_server.php <port> [http|https] [cert_dir]
 * Routes: /echo /status/{code} /slow?ms= /redirect?n=&cross= /redirect-303 /large?bytes= /json-invalid
 *         /drop (close without response) /half (partial chunked body then close) /close (Connection: close)
 *         /flaky (drops the first connection per client token, succeeds afterwards) /stats /cookies /head
 */

$port = (int) ($argv[1] ?? 18600);
$tls = ($argv[2] ?? 'http') === 'https';
$certDir = $argv[3] ?? dirname(__DIR__, 2) . '/var/tls';

$server = new OpenSwoole\Http\Server('127.0.0.1', $port, OpenSwoole\Server::POOL_MODE, $tls ? (OpenSwoole\Constant::SOCK_TCP | OpenSwoole\Constant::SSL) : OpenSwoole\Constant::SOCK_TCP);
$settings = ['worker_num' => 1, 'log_level' => OpenSwoole\Constant::LOG_ERROR, 'log_file' => dirname(__DIR__, 2) . '/var/log/fixture-upstream.log', 'open_http2_protocol' => true, 'hook_flags' => OpenSwoole\Runtime::HOOK_ALL, 'backlog' => 1024, 'http_compression' => false];
if ($tls) {
    $settings['ssl_cert_file'] = $certDir . '/server.crt';
    $settings['ssl_key_file'] = $certDir . '/server.key';
}
$server->set($settings);
$flaky = new OpenSwoole\Table(1024);
$flaky->column('hits', OpenSwoole\Table::TYPE_INT);
$flaky->create();

$server->on('request', static function (OpenSwoole\Http\Request $req, OpenSwoole\Http\Response $resp) use ($server, $flaky): void {
    $swoole = $server;
    $meta = is_array($req->server) ? $req->server : [];
    $uri = is_string($meta['request_uri'] ?? null) ? $meta['request_uri'] : '/';
    $query = is_array($req->get) ? $req->get : [];
    $fd = is_int($req->fd) ? $req->fd : 0;
    $int = static fn(string $k, int $d): int => is_string($query[$k] ?? null) && ctype_digit($query[$k]) ? (int) $query[$k] : $d;
    if (str_starts_with($uri, '/status/')) {
        $code = (int) substr($uri, 8);
        $resp->status($code);
        $resp->end('status ' . $code);

        return;
    }
    switch ($uri) {
        case '/echo':
            $resp->header('Content-Type', 'application/json');
            $resp->end((string) json_encode(['method' => $meta['request_method'] ?? null, 'uri' => $uri, 'query' => $query, 'headers' => $req->header, 'body' => $req->rawContent(), 'protocol' => $meta['server_protocol'] ?? null]));
            return;
        case '/slow':
            usleep($int('ms', 500) * 1000);
            $resp->header('Content-Type', 'application/json');
            $resp->end((string) json_encode(['slept_ms' => $int('ms', 500)]));
            return;
        case '/redirect':
            $n = $int('n', 1);
            $resp->status(302);
            $resp->header('Location', $n > 1 ? '/redirect?n=' . ($n - 1) : '/echo?from=redirect');
            $resp->end();
            return;
        case '/redirect-303':
            $resp->status(303);
            $resp->header('Location', '/echo?after=303');
            $resp->end();
            return;
        case '/large':
            $resp->header('Content-Type', 'application/octet-stream');
            $resp->end(str_repeat('L', $int('bytes', 5_000_000)));
            return;
        case '/json-invalid':
            $resp->header('Content-Type', 'application/json');
            $resp->end('{"broken":');
            return;
        case '/drop':
            $swoole->close($fd);
            return;
        case '/half':
            $resp->write(str_repeat('H', 1000));
            $swoole->close($fd);
            return;
        case '/close':
            $resp->header('Connection', 'close');
            $resp->end('closing');
            return;
        case '/flaky':
            $key = is_string($query['key'] ?? null) ? $query['key'] : 'default';
            $hits = $flaky->incr($key, 'hits');
            if ($hits === 1) {
                $swoole->close($fd);

                return;
            }
            $resp->end('recovered after ' . $hits . ' hits');
            return;
        case '/cookies':
            $resp->cookie('a', '1');
            $resp->cookie('b', '2');
            $resp->end('cookies');
            return;
        case '/stats':
            $stats = $swoole->stats();
            $resp->header('Content-Type', 'application/json');
            $resp->end((string) json_encode(['connections_accepted' => $stats['connections_accepted'] ?? null, 'requests_total' => $stats['requests_total'] ?? null]));
            return;
        default:
            $resp->status(404);
            $resp->end('no such upstream route');
    }
});
$server->start();
