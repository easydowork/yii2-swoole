<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Server;

use Swoole\Coroutine\Http\Server as CoroutineHttpServer;
use function Swoole\Coroutine\run;
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
     * @var RequestDispatcherInterface|array|string
     */
    public $dispatcher;

    /**
     * @var callable|null
     */
    public $serverFactory;

    private ?CoroutineHttpServer $server = null;

    public function init(): void
    {
        parent::init();

        if (!isset($this->dispatcher)) {
            throw new InvalidConfigException('Property "dispatcher" must be set.');
        }

        $this->dispatcher = Instance::ensure($this->dispatcher, RequestDispatcherInterface::class);

        if ($this->serverFactory === null) {
            $this->serverFactory = static function (string $host, int $port): CoroutineHttpServer {
                return new CoroutineHttpServer($host, $port, false, false);
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

        $dispatcher = $this->dispatcher;

        run(function () use ($dispatcher): void {
            $factory = $this->serverFactory;
            $this->server = $factory($this->host, $this->port);

            if (!empty($this->settings)) {
                $this->server->set($this->settings);
            }

            $server = $this->server;

            $handler = function (Request $request, Response $response) use ($dispatcher, $server): void {
                try {
                    $dispatcher->dispatch($request, $response, $server);
                } catch (\Throwable $e) {
                    if (!$response->isWritable()) {
                        return;
                    }
                    
                    $response->status(500);
                    $response->header('Content-Type', 'text/plain; charset=UTF-8');
                    $body = defined('YII_DEBUG') && YII_DEBUG ? (string) $e : 'Internal Server Error';
                    $response->end($body);
                }
            };

            // Register handlers for root and any sub-path
            $this->server->handle('/', $handler);
            $this->server->handle('/{path:.*}', $handler);

            $this->trigger(self::EVENT_AFTER_START);

            try {
                $this->server->start();
            } finally {
                $this->server = null;
                $this->trigger(self::EVENT_AFTER_STOP);
            }
        });
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

        $this->server->shutdown();
    }

}
