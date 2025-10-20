<?php

declare(strict_types=1);

use Swoole\Coroutine;
use Swoole\Coroutine\Http\Server;

$host = '127.0.0.1';
$port = 9501;

// Enable coroutine hooks
Coroutine::set([
    'hook_flags' => SWOOLE_HOOK_ALL,
    'max_coroutine' => 100000,
    'log_level' => SWOOLE_LOG_WARNING,
]);

Coroutine\run(function () use ($host, $port) {
    $server = new Server($host, $port, false, true);

    // Set server options
    $server->set([
        'open_tcp_nodelay' => true,
        'tcp_fastopen' => true,
    ]);

    echo "Swoole Coroutine HTTP server started at http://{$host}:{$port}\n";

    $server->handle('/', function ($request, $response) {
        $response->header('Content-Type', 'application/json; charset=utf-8');

        $payload = [
            'method' => $request->server['request_method'] ?? 'GET',
            'uri' => $request->server['request_uri'] ?? '/',
            'query' => $request->get ?? [],
            'post' => $request->post ?? [],
            'headers' => $request->header ?? [],
            'time' => date(DATE_ATOM),
        ];

        $response->end(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    });

    $server->start();
});
