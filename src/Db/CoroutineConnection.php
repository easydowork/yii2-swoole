<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Db;

use PDO;
use yii\db\Connection;

class CoroutineConnection extends Connection
{
    public int $poolMaxActive = 20;

    public int $poolMinActive = 0;

    public float $poolWaitTimeout = 3.0;

    public bool $enableCoroutinePooling = true;

    /**
     * @var array<string, CoroutineConnectionPool>
     */
    private static array $sharedPools = [];

    private ?string $poolKey = null;

    private bool $released = false;

    public function open(): void
    {
        if ($this->pdo !== null) {
            return;
        }

        if (!$this->isPoolingEnabled()) {
            parent::open();

            return;
        }

        $this->pdo = $this->ensurePool()->acquire();
        $this->released = false;

        $this->trigger(self::EVENT_AFTER_OPEN);
    }

    public function close(): void
    {
        if (!$this->isPoolingEnabled()) {
            parent::close();

            return;
        }

        if ($this->released || $this->pdo === null) {
            return;
        }

        $pdo = $this->pdo;
        $this->released = true;

        parent::close();

        try {
            $this->ensurePool()->release($pdo);
        } catch (\Throwable $exception) {
            throw $exception;
        }
    }

    public function reset(): void
    {
        $this->close();
    }

    private function ensurePool(): CoroutineConnectionPool
    {
        $key = $this->poolKey ??= $this->buildPoolKey();

        if (!isset(self::$sharedPools[$key])) {
            self::$sharedPools[$key] = new CoroutineConnectionPool(
                fn (): PDO => $this->createPdoForPool(),
                $this->poolMaxActive,
                $this->poolMinActive,
                $this->poolWaitTimeout
            );
        }

        return self::$sharedPools[$key];
    }

    public function getPool(): CoroutineConnectionPool
    {
        return $this->ensurePool();
    }

    private function buildPoolKey(): string
    {
        return md5(implode('|', [
            static::class,
            (string) $this->dsn,
            (string) $this->username,
            (string) $this->charset,
        ]));
    }

    private function createPdoForPool(): PDO
    {
        $pdo = parent::createPdoInstance();

        $original = $this->pdo;
        $this->pdo = $pdo;
        $this->initConnection();
        $this->pdo = $original;

        return $pdo;
    }

    private function isPoolingEnabled(): bool
    {
        return $this->enableCoroutinePooling && \Swoole\Coroutine::getCid() >= 0;
    }

}
