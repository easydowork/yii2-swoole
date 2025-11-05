<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Db;

use PDO;
use Swoole\Coroutine\Channel;
use yii\db\Connection;

class CoroutineDbConnection extends Connection
{
    public int $poolMaxActive = 20;

    public float $poolWaitTimeout = 3.0;

    public bool $enableCoroutinePooling = true;

    /**
     * @var array<string, CoroutineConnectionPool>
     */
    private static array $sharedPools = [];

    /**
     * @var array<string, Channel>
     */
    private static array $poolLocks = [];

    /**
     * @var bool Whether shutdown function has been registered
     */
    private static bool $shutdownRegistered = false;

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

        if ($this->pdo === null) {
            return;
        }

        if (!$this->released) {
            $pdo = $this->pdo;
            $this->released = true;

            parent::close();

            try {
                $this->ensurePool()->release($pdo);
            } catch (\Throwable $e) {
                error_log('[CoroutineDbConnection] Error releasing connection to pool: ' . $e->getMessage());
            }
        } else {
            parent::close();
        }
    }

    public function reset(): void
    {
        if ($this->pdo !== null && !$this->released) {
            $this->close();
        }
        $this->released = false;
        $this->pdo = null;
    }

    private function ensurePool(): CoroutineConnectionPool
    {
        $key = $this->poolKey ??= $this->buildPoolKey();

        // Register shutdown function on first pool creation as a safety net
        if (!self::$shutdownRegistered) {
            self::registerShutdownHandler();
        }

        if (!isset(self::$sharedPools[$key])) {
            $lock = self::$poolLocks[$key] ??= $this->createPoolLock();
            $token = $lock->pop();

            try {
                if (!isset(self::$sharedPools[$key])) {
                    self::$sharedPools[$key] = new CoroutineConnectionPool(
                        fn (): PDO => $this->createPdoForPool(),
                        $this->poolMaxActive,
                        $this->poolWaitTimeout
                    );
                }
            } finally {
                $lock->push($token);
            }
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

    private function createPoolLock(): Channel
    {
        $lock = new Channel(1);
        $lock->push(true);

        return $lock;
    }

    /**
     * Shuts down all connection pools
     * This should be called during application shutdown
     */
    public static function shutdownAllPools(): void
    {
        // Shutdown all pools
        foreach (self::$sharedPools as $pool) {
            try {
                $pool->shutdown();
            } catch (\Throwable $e) {
                // Silently handle shutdown errors
            }
        }
        
        // Close and clear all pool locks
        foreach (self::$poolLocks as $lock) {
            try {
                if ($lock instanceof Channel) {
                    $lock->close();
                }
            } catch (\Throwable $e) {
                // Silently handle lock close errors (channel may already be closed)
            }
        }
        
        self::$sharedPools = [];
        self::$poolLocks = [];
    }

    /**
     * Registers a PHP shutdown function as a safety net to ensure pools are cleaned up
     * even if normal shutdown sequence fails (e.g., fatal error, crash)
     */
    private static function registerShutdownHandler(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }

        self::$shutdownRegistered = true;

        register_shutdown_function(function (): void {
            // Only cleanup if pools/locks still exist
            // This prevents double cleanup during normal shutdown (since shutdownAllPools() clears the arrays)
            if (!empty(self::$sharedPools) || !empty(self::$poolLocks)) {
                try {
                    self::shutdownAllPools();
                } catch (\Throwable $e) {
                    // Silently handle errors during shutdown handler
                    error_log('[CoroutineDbConnection] Error in shutdown handler: ' . $e->getMessage());
                }
            }
        });
    }
}
