# yii2-swoole

Yii2 extension for Swoole coroutines: single-process asynchronous HTTP server, database/Redis connection pools, and more for high-concurrency PHP apps.

**Architecture**: Uses Swoole Coroutine HTTP Server for single-process execution with coroutine-based concurrency.

## Installation

```bash
composer require dacheng-php/yii2-swoole
```

## Usage

1. Register the bootstrap component in your application configuration (`config/swoole.php`):

```php
return [
    'bootstrap' => [
        \Dacheng\Yii2\Swoole\Bootstrap::class,
        [
            'class' => \Dacheng\Yii2\Swoole\Console\SwooleController::class,
            'componentId' => 'swooleHttpServer',
        ],
    ],
    'components' => [
        'swooleHttpServer' => [
            'class' => \Dacheng\Yii2\Swoole\Server\HttpServer::class,
            'host' => '0.0.0.0',
            'port' => 9501,
            'settings' => [
                // Single-process coroutine server settings
                'open_tcp_nodelay' => true,
                'tcp_fastopen' => true,
                'max_coroutine' => 100000,
                'log_level' => SWOOLE_LOG_WARNING,
            ],
            'dispatcher' => new \Dacheng\Yii2\Swoole\Server\RequestDispatcher(__DIR__ . '/web.php'),
        ],
    ],
];
```

2. Use the provided console command to control the coroutine server:

```bash
php yii swoole/start
```

To stop a running server (from another terminal):

```bash
php yii swoole/stop
```

During dispatch the original Swoole request instance is available via `Yii::$app->params['__swooleRequest']`.

## Development

```bash
composer install
```
