<?php

declare(strict_types=1);

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

$host = '127.0.0.1';
$port = 9501;

$server = new Server($host, $port);

$server->set([
    'reactor_num' => swoole_cpu_num(),
    'worker_num' => swoole_cpu_num() * 2,
//    'task_worker_num' => swoole_cpu_num(),
    'max_request' => 0,
    'max_conn' => 12544,
    'backlog' => 8192,
    'dispatch_mode' => 2,
    'http_compression' => false,
    'open_tcp_nodelay' => true,
    'tcp_fastopen' => true,
    'buffer_output_size' => 32 * 1024 * 1024,
    'socket_buffer_size' => 8 * 1024 * 1024,
    'enable_coroutine' => true,
//    'task_enable_coroutine' => true,
    'hook_flags' => SWOOLE_HOOK_ALL,
    'max_coroutine' => 100000,
    'log_level' => SWOOLE_LOG_WARNING,
]);

$server->on('start', function (Server $server) use ($host, $port) {
    echo "Swoole HTTP server started at http://{$host}:{$port}\n";
});

$server->on('request', function (Request $request, Response $response) {
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
