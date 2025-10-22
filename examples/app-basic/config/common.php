<?php

return [
    'bootstrap' => [
        [
            'class' => \Dacheng\Yii2\Swoole\Bootstrap::class,
            'componentId' => 'swooleHttpServer',
            'memoryLimit' => '2G',
            'hookFlags' => SWOOLE_HOOK_ALL,
        ],
        'queue',
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
            'poolMaxActive' => (int)(getenv('YII_REDIS_POOL_MAX_ACTIVE') ?: 20),
            'poolWaitTimeout' => (float)(getenv('YII_REDIS_POOL_WAIT_TIMEOUT') ?: 5.0),
        ],
        'cache' => [
            'class' => \yii\caching\ArrayCache::class,
        ],
        'queue' => [
            'class' => \Dacheng\Yii2\Swoole\Queue\CoroutineRedisQueue::class,
            'redis' => 'redis', // Reference to redis component
            'channel' => 'queue', // Redis key prefix for queue data
            
            // Concurrency settings:
            // - Set to 1 for serial processing (safe, compatible with all job types)
            // - Set to 10-50 for I/O intensive jobs (database queries, API calls, file operations)
            // - Set to 100+ for lightweight jobs with minimal processing
            // Note: Each concurrent worker runs in a separate coroutine
            'concurrency' => (int)(getenv('YII_QUEUE_CONCURRENCY') ?: 10),
            
            // Inline execution (recommended for coroutine context):
            // - true: Execute jobs in the same process (faster, lower overhead)
            // - false: Fork child processes for each job (slower, better isolation)
            'executeInline' => true,
        ],
        'db' => [
            'class' => \Dacheng\Yii2\Swoole\Db\CoroutineDbConnection::class,
            'dsn' => getenv('YII_DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=yii2basic',
            'username' => getenv('YII_DB_USERNAME') ?: 'root',
            'password' => getenv('YII_DB_PASSWORD') ?: '',
            'charset' => 'utf8mb4',
            'poolMaxActive' => (int)(getenv('YII_DB_POOL_MAX_ACTIVE') ?: 10),
            'poolWaitTimeout' => (float)(getenv('YII_DB_POOL_WAIT_TIMEOUT') ?: 5.0),
            'enableSchemaCache' => true,
            'schemaCacheDuration' => (int)(getenv('YII_DB_SCHEMA_CACHE_DURATION') ?: 3600),
            'schemaCache' => 'cache',
        ],
        'swooleHttpServer' => [
            'class' => \Dacheng\Yii2\Swoole\Server\HttpServer::class,
            'host' => '127.0.0.1',
            'port' => 9501,
            'documentRoot' => dirname(__DIR__) . '/web',
            'settings' => [
                'open_tcp_nodelay' => true,
                'tcp_fastopen' => true,
                'max_coroutine' => 100000,
                'log_level' => YII_DEBUG ? SWOOLE_LOG_DEBUG : SWOOLE_LOG_WARNING,
            ],
            'dispatcher' => new \Dacheng\Yii2\Swoole\Server\RequestDispatcher(__DIR__ . '/web.php'),
        ],
    ],
];

