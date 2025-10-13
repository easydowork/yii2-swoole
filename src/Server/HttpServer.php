<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Server;

use Swoole\Http\Server as SwooleHttpServer;
use Swoole\Http\Request;
use Swoole\Http\Response;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\di\Instance;

class HttpServer extends Component
{
    public const EVENT_BEFORE_START = 'beforeStart';

    public const EVENT_AFTER_START = 'afterStart';

    public const EVENT_BEFORE_STOP = 'beforeStop';

    public const EVENT_AFTER_STOP = 'afterStop';

    public string $host = '127.0.0.1';

    public int $port = 9501;

    public array $settings = [];

    /**
     * @var string|array|HotReloader
     */
    public $hotReloader = HotReloader::class;

    /**
     * @var RequestDispatcherInterface|array|string
     */
    public $dispatcher;

    /**
     * @var callable|null
     */
    public $serverFactory;

    private ?SwooleHttpServer $server = null;

    private HotReloader $hotReloaderInstance;

    public function init(): void
    {
        parent::init();

        if (!isset($this->dispatcher)) {
            throw new InvalidConfigException('Property "dispatcher" must be set.');
        }

        $this->dispatcher = Instance::ensure($this->dispatcher, RequestDispatcherInterface::class);

        $this->hotReloaderInstance = Instance::ensure($this->hotReloader, HotReloader::class);

        if ($this->serverFactory === null) {
            $this->serverFactory = static function (string $host, int $port): SwooleHttpServer {
                return new SwooleHttpServer($host, $port);
            };
        }

        if (!is_callable($this->serverFactory)) {
            throw new InvalidConfigException('Property "serverFactory" must be a valid callable.');
        }
    }

    /**
     * Starts swoole http server.
     *
     * This triggers events: beforeStart, afterStart, afterStop.
     * This bootstraps a main coroutine through `run()`, and runs http server inside it.
     * This delegates swoole request to yii2 request through Dispatcher.
     */
    public function start(): void
    {
        if ($this->server !== null) {
            return;
        }

        $this->trigger(self::EVENT_BEFORE_START);

        $factory = $this->serverFactory;
        $this->server = $factory($this->host, $this->port);

        if (!empty($this->settings)) {
            $this->server->set($this->settings);
        }

        $dispatcher = $this->dispatcher;
        $server = $this->server;

        $this->hotReloaderInstance->start($server);

        $this->server->on('request', function (Request $request, Response $response) use ($dispatcher, $server): void {
            try {
                $dispatcher->dispatch($request, $response, $server);
            } catch (\Throwable $e) {
                if (method_exists($response, 'isWritable') && !$response->isWritable()) {
                    return;
                }

                $response->status(500);
                $response->header('Content-Type', 'text/plain; charset=UTF-8');
                $body = defined('YII_DEBUG') && YII_DEBUG ? (string) $e : 'Internal Server Error';
                $response->end($body);
            }
        });

        $this->trigger(self::EVENT_AFTER_START);

        try {
            $this->server->start();
        } finally {
            $this->server = null;
            $this->hotReloaderInstance->stop();
            $this->trigger(self::EVENT_AFTER_STOP);
        }
    }

    /**
     * Stops swoole http server.
     *
     * This triggers event: beforeStop.
     */
    public function stop(): void
    {
        if ($this->server === null) {
            return;
        }

        $this->trigger(self::EVENT_BEFORE_STOP);

        $this->hotReloaderInstance->stop();

        $this->server->shutdown();
    }

    public function enableHotReload(bool $enable, array $paths = []): void
    {
        $this->hotReloaderInstance->enable($enable, $paths);
    }

}
