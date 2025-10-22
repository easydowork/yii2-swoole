<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole;

use Yii;
use yii\base\BootstrapInterface;
use yii\base\InvalidConfigException;

class Bootstrap implements BootstrapInterface
{
    public const DEFAULT_COMPONENT_ID = 'swooleHttpServer';

    public string $componentId = self::DEFAULT_COMPONENT_ID;

    public string $memoryLimit = '512M';

    public int $hookFlags = SWOOLE_HOOK_ALL;

    public function bootstrap($app): void
    {
        if ($memoryLimit = getenv('SWOOLE_MEMORY_LIMIT') ?: $this->memoryLimit) {
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
