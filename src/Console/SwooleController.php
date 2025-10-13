<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Console;

use Dacheng\Yii2\Swoole\Server\HttpServer;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\di\Instance;

class SwooleController extends Controller
{
    public string $serverComponent = 'swooleHttpServer';

    /**
     * @var string watch ',' separated path to hot reload
     */
    public $watch;

    private ?HttpServer $server = null;

    public function options($actionID): array
    {
        $options = array_merge(parent::options($actionID), ['serverComponent']);

        if ($actionID === 'start') {
            $options[] = 'watch';
        }

        return $options;
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), [
            'c' => 'serverComponent',
            'w' => 'watch',
        ]);
    }

    /**
     * Starts swoole http server
     *
     * @param string|null $host Optional listen host override, defaults to 127.0.0.1
     * @param string|null $port Optional listen port override, defaults to 9501
     */
    public function actionStart(?string $host = null, ?string $port = null): int
    {
        $server = $this->resolveServer();
        if ($server === null) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        [$watchEnabled, $watchPaths] = $this->resolveWatchOption();

        if (method_exists($server, 'enableHotReload')) {
            $server->enableHotReload($watchEnabled, $watchPaths);
        } else {
            if (property_exists($server, 'hotReloadEnabled')) {
                $server->hotReloadEnabled = $watchEnabled;
            }

            if ($watchPaths !== [] && property_exists($server, 'hotReloadWatchPaths')) {
                $server->hotReloadWatchPaths = $watchPaths;
            }
        }

        $listenHost = $host ?? $server->host;
        if ($listenHost !== null) {
            $server->host = $listenHost;
        }

        $listenPort = $port ?? $server->port;
        if ($listenPort !== null) {
            if (!is_numeric($listenPort)) {
                $this->stderr("Invalid port specified.\n");
                return ExitCode::UNSPECIFIED_ERROR;
            }

            $portNumber = (int) $listenPort;
            if ($portNumber < 1 || $portNumber > 65535) {
                $this->stderr("Port must be between 1 and 65535.\n");
                return ExitCode::UNSPECIFIED_ERROR;
            }

            $server->port = $portNumber;
        }

        $this->stdout(sprintf("Swoole HTTP server listening on %s:%d\n", $server->host, $server->port));

        $server->start();

        return ExitCode::OK;
    }

    private function resolveWatchOption(): array
    {
        $option = $this->watch;
        $enabled = false;
        $paths = [];

        if (is_bool($option)) {
            $enabled = $option;
        } elseif (is_string($option)) {
            $value = trim($option);
            if ($value !== '') {
                $boolValue = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($boolValue === null) {
                    $enabled = true;
                    $paths = array_filter(array_map('trim', explode(',', $value)), static fn($path) => $path !== '');
                } else {
                    $enabled = $boolValue;
                }
            }
        } elseif (is_array($option)) {
            $paths = array_filter(array_map('trim', $option), static fn($path) => $path !== '');
            $enabled = $paths !== [];
        }

        return [$enabled, $paths];
    }

    /**
     * Stops swoole http server
     */
    public function actionStop(): int
    {
        $server = $this->resolveServer(false);
        if ($server === null) {
            $this->stdout("Server component not found or not running.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $server->stop();
        $this->stdout("Swoole HTTP server stopped.\n");

        return ExitCode::OK;
    }

    protected function resolveServer(bool $ensure = true): ?HttpServer
    {
        if ($this->server !== null) {
            return $this->server;
        }

        if (!Yii::$app->has($this->serverComponent)) {
            if ($ensure) {
                $this->stderr(sprintf("Unable to locate server component '%s'.\n", $this->serverComponent));
            }
            return null;
        }

        $server = Instance::ensure(Yii::$app->get($this->serverComponent), HttpServer::class);

        return $this->server = $server;
    }
}
