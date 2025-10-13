<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole;

use Swoole\Runtime;
use Yii;
use yii\base\BootstrapInterface;
use yii\base\InvalidConfigException;

class Bootstrap implements BootstrapInterface
{
    public const DEFAULT_COMPONENT_ID = 'swooleHttpServer';

    public string $componentId = self::DEFAULT_COMPONENT_ID;

    public string $memoryLimit = '512M';

    public int $hookFlags = 0;

    public function bootstrap($app): void
    {
        if (extension_loaded('swoole') && method_exists(Runtime::class, 'enableCoroutine') && $this->hookFlags !== 0) {
            Runtime::enableCoroutine($this->hookFlags);
        }

        $memoryLimit = getenv('SWOOLE_MEMORY_LIMIT') ?: $this->memoryLimit;
        if ($memoryLimit !== '' && function_exists('ini_set')) {
            ini_set('memory_limit', $memoryLimit);
        }

        Yii::setAlias('@dacheng/swoole', __DIR__);

        $componentId = $this->componentId;

        if (!$app->has($componentId)) {
            return;
        }

        $component = $app->get($componentId, false);
        if (!$component instanceof Server\HttpServer) {
            throw new InvalidConfigException(sprintf(
                'Component "%s" must be instance of %s, %s given.',
                $componentId,
                Server\HttpServer::class,
                is_object($component) ? get_class($component) : gettype($component)
            ));
        }

        if ($app instanceof \yii\console\Application) {
            $controllerMap = $app->controllerMap;
            if (!isset($controllerMap['swoole'])) {
                $app->controllerMap['swoole'] = [
                    'class' => Console\SwooleController::class,
                    'serverComponent' => $componentId,
                ];
            }
        }
    }
}
