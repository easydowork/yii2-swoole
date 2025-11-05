<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Redis;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use yii\redis\Connection as BaseRedisConnection;
use yii\redis\SocketException;

/**
 * CoroutineRedisConnection provides connection pooling for Redis in Swoole coroutine context.
 * 
 * It extends yii2-redis Connection and manages a pool of socket connections that are shared
 * across coroutines. The implementation works by:
 * 
 * 1. Acquiring a socket from the coroutine pool on open()
 * 2. Injecting it into the base class's internal _pool array
 * 3. Returning it to the pool on close()
 * 
 * This approach ensures compatibility with all base class methods while providing
 * efficient connection pooling in coroutine environments.
 */
class CoroutineRedisConnection extends BaseRedisConnection
{
    /**
     * @var int Maximum number of connections in the pool
     */
    public int $poolMaxActive = 32;

    /**
     * @var float Maximum time to wait for an available connection (seconds)
     */
    public float $poolWaitTimeout = 3.0;

    /**
     * @var bool Whether to enable connection pooling in coroutine context
     */
    public bool $enableCoroutinePooling = true;

    /**
     * @var array<string, CoroutineRedisConnectionPool> Shared pools indexed by connection key
     */
    private static array $sharedPools = [];

    /**
     * @var array<string, Channel> Locks to serialize pool initialization per key
     */
    private static array $poolLocks = [];

    /**
     * @var bool Whether shutdown function has been registered
     */
    private static bool $shutdownRegistered = false;

    /**
     * @var string|null Cache of the pool key for this connection
     */
    private ?string $poolKey = null;

    /**
     * @var resource|null The current socket resource from the pool
     */
    private $currentSocket = null;

    /**
     * @var bool Whether the current socket encountered a failure during use
     */
    private bool $currentSocketFailed = false;

    /**
     * @var bool Whether the current socket has been released back to the pool
     */
    private bool $released = false;

    /**
     * @var \ReflectionProperty|null Cached reflection property for accessing parent's _pool
     */
    private static ?\ReflectionProperty $poolProperty = null;

    /**
     * Opens the Redis connection.
     * 
     * If pooling is enabled and we're in a coroutine context, acquires a socket from the pool.
     * Otherwise falls back to the parent implementation.
     */
    public function open(): void
    {
        if ($this->socket !== false) {
            return;
        }

        if (!$this->isPoolingEnabled()) {
            parent::open();
            return;
        }

        // Acquire socket from coroutine pool
        $socket = $this->acquireSocket();
        $this->currentSocket = $socket;
        $this->currentSocketFailed = false;
        $this->released = false;

        // Inject into base class's _pool array so all base class methods work
        $this->setPoolSocket($socket);

        // Initialize connection (AUTH, SELECT, etc)
        $this->initializeConnection();
    }

    /**
     * Closes the Redis connection.
     * 
     * If pooling is enabled, returns the socket to the pool instead of closing it.
     */
    public function close(): void
    {
        if (!$this->isPoolingEnabled()) {
            parent::close();
            return;
        }

        if ($this->released || $this->currentSocket === null) {
            return;
        }

        $socket = $this->currentSocket;
        $failed = $this->currentSocketFailed;

        $this->currentSocket = null;
        $this->currentSocketFailed = false;
        $this->released = true;

        // Remove from base class's _pool array
        $this->clearPoolSocket();

        // Return to coroutine pool or discard on failure
        if ($failed) {
            $this->ensurePool()->discard($socket);
        } else {
            $this->releaseSocket($socket);
        }
    }

    /**
     * Resets the connection by closing it.
     */
    public function reset(): void
    {
        $this->close();
    }

    /**
     * Returns the connection pool instance.
     */
    public function getPool(): CoroutineRedisConnectionPool
    {
        return $this->ensurePool();
    }

    /**
     * Returns statistics about the connection pool.
     * 
     * @return array{created: int, idle: int, in_use: int, waiters: int, capacity: int}
     */
    public function getPoolStats(): array
    {
        return $this->ensurePool()->getStats();
    }

    /**
     * Acquires a socket from the coroutine pool.
     * Validates the socket and retries if it's no longer alive.
     * 
     * @return resource
     */
    private function acquireSocket()
    {
        $pool = $this->ensurePool();
        $socket = $pool->acquire();
        
        // Fast path: assume socket is alive (most common case)
        // Failures are detected lazily during command execution
        return $socket;
    }

    /**
     * Returns a socket back to the coroutine pool.
     * 
     * @param resource $socket
     */
    private function releaseSocket($socket): void
    {
        // Fast path: assume socket is healthy
        // Dead sockets will be detected on next acquire
        $this->ensurePool()->release($socket);
    }

    /**
     * Gets or creates the connection pool for this configuration.
     */
    private function ensurePool(): CoroutineRedisConnectionPool
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
                    self::$sharedPools[$key] = new CoroutineRedisConnectionPool(
                        fn() => $this->createSocketForPool(),
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

    /**
     * Builds a unique key for the connection pool based on connection parameters.
     */
    private function buildPoolKey(): string
    {
        return md5(implode('|', [
            static::class,
            $this->hostname,
            $this->port,
            $this->unixSocket ?? '',
            $this->database ?? '',
            $this->username ?? '',
        ]));
    }

    /**
     * Checks if connection pooling should be enabled.
     */
    private function isPoolingEnabled(): bool
    {
        return $this->enableCoroutinePooling && Coroutine::getCid() >= 0;
    }

    private function createPoolLock(): Channel
    {
        $lock = new Channel(1);
        $lock->push(true);

        return $lock;
    }

    /**
     * Creates a new socket for the pool.
     * This is called by the pool when it needs to create new connections.
     * 
     * IMPORTANT: AUTH and SELECT are performed HERE during socket creation,
     * not on every acquire. This is a critical performance optimization.
     * 
     * @return resource
     */
    private function createSocketForPool()
    {
        $connection = $this->connectionString . ', database=' . $this->database;
        \Yii::trace('Creating redis socket for pool: ' . $connection, __METHOD__);

        $socket = @stream_socket_client(
            $this->connectionString,
            $errorNumber,
            $errorDescription,
            $this->connectionTimeout ?: (float)ini_get('default_socket_timeout'),
            $this->socketClientFlags,
            stream_context_create($this->contextOptions)
        );

        if (!is_resource($socket)) {
            \Yii::error("Failed to create redis socket ($connection): $errorNumber - $errorDescription", __CLASS__);
            $message = YII_DEBUG 
                ? "Failed to create redis socket ($connection): $errorNumber - $errorDescription" 
                : 'Failed to create redis connection.';
            throw new SocketException($message, $errorNumber);
        }

        if ($this->dataTimeout !== null) {
            stream_set_timeout(
                $socket,
                $timeout = (int)$this->dataTimeout,
                (int)(($this->dataTimeout - $timeout) * 1_000_000)
            );
        }

        if ($this->useSSL) {
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        }

        // Perform AUTH and SELECT during socket creation (PERFORMANCE CRITICAL)
        // This way we don't repeat these commands on every acquire
        $this->authenticateSocket($socket);

        return $socket;
    }

    /**
     * Authenticates a socket during creation.
     * This is done once per socket, not on every acquire.
     * 
     * @param resource $socket
     */
    private function authenticateSocket($socket): void
    {
        // Temporarily inject socket into _pool for executeCommand to work
        $property = self::getPoolProperty();
        $pool = $property->getValue($this);
        $oldSocket = $pool[$this->connectionString] ?? null;
        $pool[$this->connectionString] = $socket;
        $property->setValue($this, $pool);

        $previousSocket = $this->currentSocket;
        $previousFailed = $this->currentSocketFailed;
        $previousReleased = $this->released;

        $this->currentSocket = $socket;
        $this->currentSocketFailed = false;
        $this->released = false;

        try {
            if ($this->password !== null) {
                $this->executeCommand('AUTH', array_filter([$this->username, $this->password]));
            }

            if ($this->database !== null) {
                $this->executeCommand('SELECT', [$this->database]);
            }
        } finally {
            // Restore original state
            $pool = $property->getValue($this);
            if ($oldSocket !== null) {
                $pool[$this->connectionString] = $oldSocket;
            } else {
                unset($pool[$this->connectionString]);
            }
            $property->setValue($this, $pool);

            $this->currentSocket = $previousSocket;
            $this->currentSocketFailed = $previousFailed;
            $this->released = $previousReleased;
        }
    }

    /**
     * Initializes the connection after acquiring a socket from the pool.
     * Performs AUTH and SELECT commands if configured.
     * 
     * Note: For pooled connections, AUTH/SELECT are done once during socket creation,
     * not on every acquire. This is a major performance optimization.
     */
    private function initializeConnection(): void
    {
        try {
            // For pooled connections, initialization was already done in createSocketForPool
            // Just trigger the application-level init hook
            $this->initConnection();
        } catch (\Throwable $e) {
            // If initialization fails, close and rethrow
            $this->close();
            throw $e;
        }
    }

    /**
     * Checks if a socket is still alive and usable.
     * 
     * @param resource $socket
     * @return bool
     */
    private function isSocketAlive($socket): bool
    {
        if (!is_resource($socket) || get_resource_type($socket) !== 'stream') {
            return false;
        }

        $meta = stream_get_meta_data($socket);
        return !($meta['eof'] ?? true);
    }

    /**
     * Destroys a socket by closing it.
     * 
     * @param resource $socket
     */
    private function destroySocket($socket): void
    {
        if (is_resource($socket) && get_resource_type($socket) === 'stream') {
            try {
                @fclose($socket);
            } catch (\Throwable $e) {
                // Ignore errors during socket destruction
            }
        }
    }

    private function markCurrentSocketAsFailed(): void
    {
        if ($this->currentSocket !== null) {
            $this->currentSocketFailed = true;
        }
    }

    protected function sendRawCommand($command, $params)
    {
        try {
            return parent::sendRawCommand($command, $params);
        } catch (SocketException $exception) {
            $this->markCurrentSocketAsFailed();
            throw $exception;
        }
    }

    /**
     * Gets the cached reflection property for the parent's _pool array.
     * 
     * @return \ReflectionProperty
     */
    private static function getPoolProperty(): \ReflectionProperty
    {
        if (self::$poolProperty === null) {
            self::$poolProperty = (new \ReflectionClass(BaseRedisConnection::class))->getProperty('_pool');
            self::$poolProperty->setAccessible(true);
        }
        return self::$poolProperty;
    }

    /**
     * Injects a socket into the base class's internal _pool array.
     * This makes all base class methods work with our pooled socket.
     * 
     * @param resource $socket
     */
    private function setPoolSocket($socket): void
    {
        $property = self::getPoolProperty();
        $pool = $property->getValue($this);
        $pool[$this->connectionString] = $socket;
        $property->setValue($this, $pool);
    }

    /**
     * Removes the socket from the base class's internal _pool array.
     */
    private function clearPoolSocket(): void
    {
        $property = self::getPoolProperty();
        $pool = $property->getValue($this);
        unset($pool[$this->connectionString]);
        $property->setValue($this, $pool);
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
            // This prevents double cleanup during normal shutdown
            if (!empty(self::$sharedPools) || !empty(self::$poolLocks)) {
                try {
                    self::shutdownAllPools();
                } catch (\Throwable $e) {
                    // Use error_log here to avoid dependency on Yii during shutdown
                    error_log('[CoroutineRedisConnection] Error in shutdown handler: ' . $e->getMessage());
                }
            }
        });
    }
}
