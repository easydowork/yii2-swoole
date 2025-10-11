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

        $components = $this->getComponents(false);
        if (array_key_exists($id, $components)) {
            $component = $components[$id];
            $this->setCoroutineComponent($id, $component);

            return $component;
        }

        $definitions = $this->getComponents();
        if (!array_key_exists($id, $definitions)) {
            if ($throwException) {
                throw new InvalidConfigException("Unknown component ID: {$id}");
            }

            return null;
        }

        $definition = $definitions[$id];
        if (is_object($definition) && !$definition instanceof Closure) {
            $component = clone $definition;
        } else {
            $component = Yii::createObject($definition);
        }

        $this->setCoroutineComponent($id, $component);

        return $component;
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
        foreach ($store as $component) {
            if (!is_object($component)) {
                continue;
            }

            if (method_exists($component, 'reset')) {
                $component->reset();
            }

            if (method_exists($component, 'clear')) {
                $component->clear();
            }

            if (method_exists($component, 'close')) {
                $component->close();
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
}
