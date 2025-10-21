<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Server;

use Dacheng\Yii2\Swoole\Db\CoroutineDbConnection;
use Dacheng\Yii2\Swoole\Log\LogWorker;
use Dacheng\Yii2\Swoole\Redis\CoroutineRedisConnection;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Server as SwooleCoroutineHttpServer;
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

    private ?SwooleCoroutineHttpServer $server = null;

    private bool $isRunning = false;

    private ?SignalHandler $signalHandler = null;

    private int $activeRequests = 0;

    public function init(): void
    {
        parent::init();

        if (!isset($this->dispatcher)) {
            throw new InvalidConfigException('Property "dispatcher" must be set.');
        }

        $this->dispatcher = Instance::ensure($this->dispatcher, RequestDispatcherInterface::class);

        if ($this->serverFactory === null) {
            $this->serverFactory = static function (string $host, int $port): SwooleCoroutineHttpServer {
                return new SwooleCoroutineHttpServer($host, $port, false, true);
            };
        }

        if (!is_callable($this->serverFactory)) {
            throw new InvalidConfigException('Property "serverFactory" must be a valid callable.');
        }

    }

    /**
     * Starts swoole coroutine http server.
     *
     * This triggers events: beforeStart, afterStart, afterStop.
     * This runs in a single process using coroutines for concurrency.
     * This delegates swoole request to yii2 request through Dispatcher.
     */
    public function start(): void
    {
        if ($this->isRunning) {
            return;
        }

        $this->trigger(self::EVENT_BEFORE_START);

        // Set coroutine configuration
        Coroutine::set([
            'hook_flags' => SWOOLE_HOOK_ALL,
            'max_coroutine' => $this->settings['max_coroutine'] ?? 100000,
            'log_level' => $this->settings['log_level'] ?? SWOOLE_LOG_WARNING,
        ]);

        $dispatcher = $this->dispatcher;
        $host = $this->host;
        $port = $this->port;
        $settings = $this->settings;
        $factory = $this->serverFactory;
        $afterStartEvent = function () {
            $this->trigger(self::EVENT_AFTER_START);
        };
        $afterStopEvent = function () {
            $this->isRunning = false;
            $this->server = null;
            $this->trigger(self::EVENT_AFTER_STOP);
        };

        $this->isRunning = true;

        // Initialize signal handler
        $this->signalHandler = new SignalHandler();
        $this->setupShutdownCallbacks();
        $this->signalHandler->register();

        Coroutine\run(function () use ($factory, $host, $port, $settings, $dispatcher, $afterStartEvent, $afterStopEvent): void {
            $this->server = $factory($host, $port);

            // Apply relevant settings for coroutine server
            $coroutineSettings = [];
            if (isset($settings['open_tcp_nodelay'])) {
                $coroutineSettings['open_tcp_nodelay'] = $settings['open_tcp_nodelay'];
            }
            if (isset($settings['tcp_fastopen'])) {
                $coroutineSettings['open_tcp_fastopen'] = $settings['tcp_fastopen'];
            }
            if (!empty($coroutineSettings)) {
                $this->server->set($coroutineSettings);
            }

            $server = $this->server;

            $afterStartEvent();

            $this->server->handle('/', function (Request $request, Response $response) use ($dispatcher, $server): void {
                // Check if shutdown is requested
                if ($this->signalHandler && $this->signalHandler->isShutdownRequested()) {
                    $response->status(503);
                    $response->header('Content-Type', 'text/plain; charset=UTF-8');
                    $response->end('Server is shutting down');
                    return;
                }

                // Track active requests
                $this->activeRequests++;
                
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
                } finally {
                    $this->activeRequests--;
                }
            });

            try {
                $this->server->start();
            } finally {
                // Cleanup signal handler
                if ($this->signalHandler) {
                    $this->signalHandler->unregister();
                }
                $afterStopEvent();
            }
        });
    }

    /**
     * Sets up shutdown callbacks for graceful shutdown
     */
    private function setupShutdownCallbacks(): void
    {
        if (!$this->signalHandler) {
            return;
        }

        // Priority 10: Wait for in-flight requests
        $this->signalHandler->onShutdown('wait_requests', function () {
            error_log('[HttpServer] Waiting for in-flight requests to complete...');
            $this->signalHandler->waitForInflightRequests(
                fn() => $this->activeRequests > 0,
                5.0
            );
        }, 10);

        // Priority 20: Stop accepting new requests (shutdown server)
        $this->signalHandler->onShutdown('stop_server', function () {
            error_log('[HttpServer] Stopping HTTP server...');
            if ($this->server) {
                $this->server->shutdown();
            }
        }, 20);

        // Priority 30: Flush logs
        $this->signalHandler->onShutdown('flush_logs', function () {
            error_log('[HttpServer] Flushing logs...');
            if (\Yii::$app->has('log')) {
                try {
                    // Stop log workers first
                    foreach (\Yii::$app->log->targets as $target) {
                        if (method_exists($target, 'getWorker')) {
                            $worker = $target->getWorker();
                            if ($worker instanceof LogWorker) {
                                $worker->stop();
                            }
                        }
                    }
                    
                    // Then export/flush remaining messages
                    foreach (\Yii::$app->log->targets as $target) {
                        if (method_exists($target, 'export')) {
                            $messages = \Yii::$app->log->getLogger()->messages;
                            if (!empty($messages)) {
                                $target->collect($messages, true);
                                $target->export();
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    error_log('[HttpServer] Error flushing logs: ' . $e->getMessage());
                }
            }
        }, 30);

        // Priority 40: Close connection pools
        $this->signalHandler->onShutdown('close_pools', function () {
            error_log('[HttpServer] Closing connection pools...');
            
            try {
                CoroutineDbConnection::shutdownAllPools();
            } catch (\Throwable $e) {
                error_log('[HttpServer] Error closing DB pools: ' . $e->getMessage());
            }
            
            try {
                CoroutineRedisConnection::shutdownAllPools();
            } catch (\Throwable $e) {
                error_log('[HttpServer] Error closing Redis pools: ' . $e->getMessage());
            }
        }, 40);
    }

    /**
     * Stops swoole coroutine http server.
     *
     * This triggers event: beforeStop.
     */
    public function stop(): void
    {
        if (!$this->isRunning || $this->server === null) {
            return;
        }

        $this->trigger(self::EVENT_BEFORE_STOP);

        $this->server->shutdown();
    }

}
