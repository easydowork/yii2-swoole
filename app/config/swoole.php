<?php

return [
    'bootstrap' => [
        [
            'class' => \Dacheng\Yii2\Swoole\Bootstrap::class,
            'componentId' => 'swooleHttpServer',
            'memoryLimit' => '2G',
            'hookFlags' => SWOOLE_HOOK_ALL,
        ],
    ],
    'components' => [
        'swooleHttpServer' => [
            'class' => \Dacheng\Yii2\Swoole\Server\HttpServer::class,
            'host' => '127.0.0.1',
            'port' => 9501,
            'settings' => [
                'reactor_num' => swoole_cpu_num(),
                'worker_num' => swoole_cpu_num() * 2,
//                'task_worker_num' => swoole_cpu_num(),
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
//                'task_enable_coroutine' => true,
                'hook_flags' => SWOOLE_HOOK_ALL,
                'max_coroutine' => 100000,
                'log_level' => YII_DEBUG ? SWOOLE_LOG_DEBUG : SWOOLE_LOG_WARNING,
                'log_file' => 'runtime/logs/swoole.log',
            ],
            'dispatcher' => new \Dacheng\Yii2\Swoole\Server\RequestDispatcher(__DIR__ . '/web.php'),
        ],
    ],
];
