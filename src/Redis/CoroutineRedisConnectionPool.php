<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Redis;

use RuntimeException;
use Swoole\Coroutine\Channel;
use yii\base\InvalidConfigException;
use yii\redis\SocketException;

/**
 * CoroutineRedisConnectionPool manages a pool of Redis socket connections for coroutine environments.
 * 
 * This pool implementation:
 * - Maintains a pool of reusable socket resources
 * - Uses Swoole channels for lock-free coordination
 * - Supports max pool sizes
 * - Handles connection creation with retry logic
 * - Provides wait timeout for pool exhaustion scenarios
 */
final class CoroutineRedisConnectionPool
{
    private Channel $channel;

    private int $maxActive;

    private float $waitTimeout;

    private $factory;

    public function __construct(callable $factory, int $maxActive, float $waitTimeout)
    {
        if ($maxActive < 1) {
            throw new InvalidConfigException('"poolMaxActive" must be greater than or equal to 1.');
        }

        $this->factory = $factory;
        $this->maxActive = $maxActive;
        $this->waitTimeout = $waitTimeout;
        $this->channel = new Channel($maxActive);

        try {
            for ($i = 0; $i < $this->maxActive; $i++) {
                $this->pushConnection($this->createConnection());
            }
        } catch (\Throwable $exception) {
            $this->drainPool();

            throw $exception;
        }
    }

    public function acquire()
    {
        $connection = $this->channel->pop($this->waitTimeout);

        if ($this->isValidConnection($connection)) {
            return $connection;
        }

        if ($connection === false) {
            $stats = $this->channel->stats();

            throw new RuntimeException(
                sprintf(
                    'Redis connection pool exhausted. Max active: %d, idle: %d, waiting consumers: %d',
                    $this->maxActive,
                    (int) ($stats['queue_num'] ?? 0),
                    (int) ($stats['consumer_num'] ?? 0)
                )
            );
        }

        if ($connection !== null) {
            $this->closeConnection($connection);
        }

        return $this->createConnection();
    }

    public function release($connection): void
    {
        if (!$this->isValidConnection($connection)) {
            $this->closeConnection($connection);
            $connection = $this->createConnection();
        }

        $this->pushConnection($connection);
    }

    public function discard($connection): void
    {
        $this->closeConnection($connection);

        $this->pushConnection($this->createConnection());
    }

    public function getStats(): array
    {
        $stats = $this->channel->stats();

        return [
            'created' => $this->maxActive,
            'idle' => (int) ($stats['queue_num'] ?? 0),
            'in_use' => max(0, $this->maxActive - (int) ($stats['queue_num'] ?? 0)),
            'waiters' => (int) ($stats['consumer_num'] ?? 0),
            'capacity' => $this->maxActive,
        ];
    }

    private function createConnection()
    {
        try {
            $connection = ($this->factory)();
        } catch (SocketException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new RuntimeException('Failed to create a redis connection for the pool.', 0, $exception);
        }

        if (!$this->isValidConnection($connection)) {
            throw new RuntimeException('Redis connection factory must return a valid connection resource.');
        }

        return $connection;
    }

    private function pushConnection($connection): void
    {
        if (!$this->channel->push($connection, 0.0)) {
            $this->closeConnection($connection);
            throw new RuntimeException('Redis connection pool channel is closed.');
        }
    }

    private function closeConnection($connection): void
    {
        if ($this->isStream($connection)) {
            try {
                @fclose($connection);
            } catch (\Throwable) {
                // Ignore errors during close
            }
        }
    }

    private function isValidConnection($connection): bool
    {
        return $this->isStream($connection);
    }

    private function isStream($connection): bool
    {
        return is_resource($connection) && get_resource_type($connection) === 'stream';
    }

    private function drainPool(): void
    {
        // Get current pool size to avoid infinite loop
        try {
            $stats = $this->channel->stats();
            $count = $stats['queue_num'] ?? 0;
        } catch (\Throwable $e) {
            return;
        }
        
        // Pop only the known number of connections with timeout
        for ($i = 0; $i < $count; $i++) {
            $connection = $this->channel->pop(0.01);
            
            if ($connection === false) {
                break;
            }
            
            if ($connection !== null) {
                try {
                    $this->closeConnection($connection);
                } catch (\Throwable $e) {
                    // Silently handle close errors
                }
            }
        }
    }

    /**
     * Gracefully shuts down the connection pool
     * Closes all connections and the channel
     */
    public function shutdown(): void
    {
        // Drain all connections from the pool
        try {
            $this->drainPool();
        } catch (\Throwable $e) {
            // Silently handle drain errors
        }
        
        // Close the channel
        try {
            $this->channel->close();
        } catch (\Throwable $e) {
            // Silently handle channel close errors
        }
    }
}
