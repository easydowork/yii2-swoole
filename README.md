# yii2-swoole

Yii2 extension for Swoole coroutines: asynchronous HTTP server, database/Redis connection pools, and more for high-concurrency PHP apps.

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
                'worker_num' => 4,
                'enable_coroutine' => true,
                'max_coroutine' => 3000,
            ],
            'dispatcher' => new \Dacheng\Yii2\Swoole\Dispatcher\YiiRequestDispatcher(__DIR__ . '/web.php'),
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
