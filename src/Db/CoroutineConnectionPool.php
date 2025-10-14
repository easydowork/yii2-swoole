<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Db;

use PDO;
use RuntimeException;
use Swoole\Coroutine\Channel;
use yii\base\InvalidConfigException;

final class CoroutineConnectionPool
{
    private Channel $channel;

    private int $maxActive;

    private float $waitTimeout;

    /**
     * @var callable
     */
    private $factory;

    /**
     * @param callable $factory creator returning a configured PDO instance
     */
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

    public function acquire(): PDO
    {
        $connection = $this->channel->pop($this->waitTimeout);

        if ($connection instanceof PDO) {
            return $connection;
        }

        if ($connection === false) {
            $stats = $this->channel->stats();

            throw new RuntimeException(
                sprintf(
                    'Database connection pool exhausted. Max active: %d, idle: %d, waiting consumers: %d',
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

    public function release(PDO $connection): void
    {
        $this->pushConnection($connection);
    }

    /**
     * @return array{0:?PDO,1:?RuntimeException}
     */
    private function closeConnection(PDO $connection): void
    {
        try {
            $connection = null;
        } catch (\Throwable $exception) {
            // ignore disposal errors
        }
    }

    /**
     * @return array{created:int,idle:int,in_use:int,waiters:int,capacity:int}
     */
    public function getStats(): array
    {
        $stats = $this->channel->stats();

        return [
            'created' => $this->maxActive,
            'idle' => (int)($stats['queue_num'] ?? 0),
            'in_use' => max(0, $this->maxActive - (int)($stats['queue_num'] ?? 0)),
            'waiters' => (int)($stats['consumer_num'] ?? 0),
            'capacity' => $this->maxActive,
        ];
    }

    private function createConnection(): PDO
    {
        try {
            /** @var PDO $connection */
            $connection = ($this->factory)();
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new RuntimeException('Failed to create a database connection for the pool.', 0, $exception);
        }

        return $connection;
    }

    private function pushConnection(PDO $connection): void
    {
        if (!$this->channel->push($connection, 0.0)) {
            $this->closeConnection($connection);
            throw new RuntimeException('Database connection pool channel is closed.');
        }
    }

    private function drainPool(): void
    {
        while (($connection = $this->channel->pop(0.0)) instanceof PDO) {
            $this->closeConnection($connection);
        }
    }
}
