<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\User;

use Swoole\Coroutine;
use yii\web\User as BaseUser;

class CoroutineUser extends BaseUser
{
    private const CONTEXT_KEY = '__yiiCoroutineUser';

    public function getIdentity($autoRenew = true)
    {
        if (!$this->isCoroutineContext()) {
            return parent::getIdentity($autoRenew);
        }

        $state = $this->getCoroutineState();
        if ($state['identity'] === false) {
            $identity = parent::getIdentity($autoRenew);
            $state = $this->getCoroutineState();
            if ($state['identity'] === false) {
                $state['identity'] = $identity;
                $this->setCoroutineState($state);
            }

            return $identity;
        }

        return $state['identity'];
    }

    public function setIdentity($identity)
    {
        parent::setIdentity($identity);

        if (!$this->isCoroutineContext()) {
            return;
        }

        $state = $this->getCoroutineState();
        $state['identity'] = $identity;
        $this->setCoroutineState($state);
    }

    public function reset(): void
    {
        if (!$this->isCoroutineContext()) {
            return;
        }

        $this->setCoroutineState(['identity' => false]);
    }

    private function isCoroutineContext(): bool
    {
        return Coroutine::getCid() >= 0;
    }

    private function getCoroutineState(): array
    {
        $context = Coroutine::getContext();
        $state = $context[self::CONTEXT_KEY] ?? null;
        if (!is_array($state) || !array_key_exists('identity', $state)) {
            $state = ['identity' => false];
            $context[self::CONTEXT_KEY] = $state;
            return $state;
        }

        return $state;
    }

    private function setCoroutineState(array $state): void
    {
        $context = Coroutine::getContext();
        if (!array_key_exists('identity', $state)) {
            $state['identity'] = false;
        }

        $context[self::CONTEXT_KEY] = $state;
    }
}
