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
        'redis' => [
            'class' => \Dacheng\Yii2\Swoole\Redis\CoroutineRedisConnection::class,
            'hostname' => getenv('YII_REDIS_HOST') ?: '127.0.0.1',
            'port' => (int)(getenv('YII_REDIS_PORT') ?: 6379),
            'database' => (int)(getenv('YII_REDIS_DATABASE') ?: 0),
            'password' => getenv('YII_REDIS_PASSWORD') ?: null,
            'socketClientFlags' => STREAM_CLIENT_CONNECT,
            'connectionTimeout' => 1.0,
            'dataTimeout' => 1.0,
            'poolMaxActive' => (int)(getenv('YII_REDIS_POOL_MAX_ACTIVE') ?: 10),
            'poolWaitTimeout' => (float)(getenv('YII_REDIS_POOL_WAIT_TIMEOUT') ?: 5.0),
        ],
        'cache' => [
            'class' => \yii\caching\ArrayCache::class,
        ],
        'db' => [
            'class' => \Dacheng\Yii2\Swoole\Db\CoroutineDbConnection::class,
            'dsn' => getenv('YII_DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=yii2swoole',
            'username' => getenv('YII_DB_USERNAME') ?: 'root',
            'password' => getenv('YII_DB_PASSWORD') ?: '',
            'charset' => 'utf8mb4',
            'poolMaxActive' => (int)(getenv('YII_DB_POOL_MAX_ACTIVE') ?: 5),
            'poolWaitTimeout' => (float)(getenv('YII_DB_POOL_WAIT_TIMEOUT') ?: 5.0),
            'enableSchemaCache' => true,
            'schemaCacheDuration' => (int)(getenv('YII_DB_SCHEMA_CACHE_DURATION') ?: 3600),
            'schemaCache' => 'cache',
        ],
        'swooleHttpServer' => [
            'class' => \Dacheng\Yii2\Swoole\Server\HttpServer::class,
            'host' => '127.0.0.1',
            'port' => 9501,
            'settings' => [
                'reactor_num' => swoole_cpu_num(),
                'worker_num' => swoole_cpu_num(),
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
                'hook_flags' => SWOOLE_HOOK_ALL,
                'max_coroutine' => 100000,
                'log_level' => YII_DEBUG ? SWOOLE_LOG_DEBUG : SWOOLE_LOG_WARNING,
                'log_file' => 'runtime/logs/swoole.log',
            ],
            'dispatcher' => new \Dacheng\Yii2\Swoole\Server\RequestDispatcher(__DIR__ . '/web.php'),
            'onWorkerStart' => function () {
                $redis = \Yii::$app->get('redis', false);
                if ($redis instanceof \Dacheng\Yii2\Swoole\Redis\CoroutineRedisConnection) {
                    $redis->getPoolStats();
                }

                $db = \Yii::$app->get('db', false);
                if ($db instanceof \Dacheng\Yii2\Swoole\Db\CoroutineDbConnection) {
                    $db->getPool();
                }
            },
        ],
    ],
];
