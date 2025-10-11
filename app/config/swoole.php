<?php

return [
    'bootstrap' => [
        [
            'class' => \Dacheng\Yii2\Swoole\Bootstrap::class,
            'componentId' => 'swooleHttpServer',
        ],
    ],
    'components' => [
        'swooleHttpServer' => [
            'class' => \Dacheng\Yii2\Swoole\Server\HttpServer::class,
            'host' => '127.0.0.1',
            'port' => 9501,
            'settings' => [
                'worker_num' => 4,
                'enable_coroutine' => true,
                'max_coroutine' => 3000,
            ],
            'dispatcher' => new \Dacheng\Yii2\Swoole\Server\RequestDispatcher(__DIR__ . '/web.php'),
        ],
    ],
];
