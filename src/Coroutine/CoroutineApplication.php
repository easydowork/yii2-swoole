<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Coroutine;

use Closure;
use Swoole\Coroutine;
use Yii;
use yii\base\InvalidConfigException;
use yii\web\Application;

class CoroutineApplication extends Application
{
    private const CONTEXT_COMPONENTS_KEY = '__yiiCoroutineComponents';

    /**
     * @var string[] component IDs that should remain shared across coroutines.
     */
    protected array $sharedComponentIds = [
        'log',
        'formatter',
        'i18n',
    ];

    /**
     * @inheritdoc
     */
    public function get($id, $throwException = true)
    {
        if (!$this->isCoroutineContext() || $this->isSharedComponent($id)) {
            return parent::get($id, $throwException);
        }

        $store = $this->getCoroutineComponentStore();
        if (array_key_exists($id, $store)) {
            return $store[$id];
        }

        $definitions = $this->getComponents();
        if (!array_key_exists($id, $definitions)) {
            if ($throwException) {
                throw new InvalidConfigException("Unknown component ID: {$id}");
            }

            return null;
        }

        $component = $this->createCoroutineComponent($definitions[$id]);
        $this->setCoroutineComponent($id, $component);

        return $component;
    }

    public function has($id, $checkInstance = false)
    {
        if ($checkInstance && $this->isCoroutineContext() && !$this->isSharedComponent($id)) {
            $store = $this->getCoroutineComponentStore();
            if (array_key_exists($id, $store)) {
                return true;
            }
        }

        return parent::has($id, $checkInstance);
    }

    /**
     * Clears coroutine-bound components and resets per-request application state.
     */
    public function resetCoroutineContext(): void
    {
        if (!$this->isCoroutineContext()) {
            return;
        }

        $store = $this->getCoroutineComponentStore();
        foreach ($store as $id => $component) {
            if (!is_object($component)) {
                continue;
            }

            if (method_exists($component, 'close')) {
                $component->close();
            }

            if (method_exists($component, 'reset')) {
                $component->reset();
            }

            if (method_exists($component, 'clear')) {
                $component->clear();
            }
        }

        $this->setCoroutineComponentStore([]);

        $this->controller = null;
        $this->requestedRoute = null;
        $this->requestedAction = null;
        $this->requestedParams = null;
        $this->requestedModule = null;
        $this->state = self::STATE_BEGIN;
    }

    protected function isSharedComponent(string $id): bool
    {
        return in_array($id, $this->sharedComponentIds, true);
    }

    protected function isCoroutineContext(): bool
    {
        $cid = Coroutine::getCid();

        return $cid >= 0;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getCoroutineComponentStore(): array
    {
        $context = Coroutine::getContext();
        $store = $context[self::CONTEXT_COMPONENTS_KEY] ?? [];

        if (!is_array($store)) {
            $store = [];
        }

        return $store;
    }

    /**
     * @param array<string, mixed> $components
     */
    protected function setCoroutineComponentStore(array $components): void
    {
        $context = Coroutine::getContext();
        $context[self::CONTEXT_COMPONENTS_KEY] = $components;
    }

    protected function setCoroutineComponent(string $id, $component): void
    {
        $store = $this->getCoroutineComponentStore();
        $store[$id] = $component;
        $this->setCoroutineComponentStore($store);
    }

    private function createCoroutineComponent($definition)
    {
        if (is_object($definition) && !$definition instanceof Closure) {
            return clone $definition;
        }

        return Yii::createObject($definition);
    }
}
