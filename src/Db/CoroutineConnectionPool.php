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

    private int $created = 0;

    private int $minActive;

    private float $waitTimeout;

    private Channel $creationLock;

    /**
     * @var callable
     */
    private $factory;

    /**
     * @param callable $factory creator returning a configured PDO instance
     */
    public function __construct(callable $factory, int $maxActive, int $minActive, float $waitTimeout)
    {
        if ($maxActive < 1) {
            throw new InvalidConfigException('"poolMaxActive" must be greater than or equal to 1.');
        }

        $this->factory = $factory;
        $this->maxActive = $maxActive;
        $this->minActive = max(0, min($minActive, $maxActive));
        $this->waitTimeout = $waitTimeout;
        $this->channel = new Channel($maxActive);
        $this->creationLock = new Channel(1);
        $this->creationLock->push(true);

        for ($i = 0; $i < $this->minActive; $i++) {
            $this->channel->push($this->createConnection());
        }
    }

    public function acquire(): PDO
    {
        $stats = $this->channel->stats();
        $idle = (int) ($stats['queue_num'] ?? 0);
        $waiters = (int) ($stats['consumer_num'] ?? 0);

        $connection = $this->channel->pop(0.0);
        if ($connection instanceof PDO) {
            if ($this->created < $this->maxActive && $waiters > 0) {
                $this->growPool($waiters);
            }

            return $connection;
        }

        [$newConnection, $creationException] = $this->growPool(max(1, $waiters + 1), true);
        if ($newConnection instanceof PDO) {
            return $newConnection;
        }

        $connection = $this->channel->pop($this->waitTimeout);
        if (!$connection instanceof PDO) {
            if ($creationException !== null) {
                throw $creationException;
            }

            throw new RuntimeException(
                sprintf(
                    'Database connection pool exhausted. Max active: %d, created: %d, waiting consumers: %d',
                    $this->maxActive,
                    $this->created,
                    $this->channel->stats()['consumer_num'] ?? 0
                )
            );
        }

        return $connection;
    }

    public function release(PDO $connection): void
    {
        if ($this->channel->push($connection, 0.0)) {
            return;
        }

        $this->created--;
        $this->closeConnection($connection);
    }

    /**
     * @return array{0:?PDO,1:?RuntimeException}
     */
    private function growPool(int $required, bool $returnFirst = false): array
    {
        if ($required <= 0 || $this->created >= $this->maxActive) {
            return [null, null];
        }

        $firstConnection = null;
        $creationException = null;
        $lock = $this->creationLock->pop();

        try {
            while ($required > 0 && $this->created < $this->maxActive) {
                try {
                    $connection = $this->createConnection();
                } catch (RuntimeException $exception) {
                    $creationException = $exception;

                    break;
                }

                if ($returnFirst && $firstConnection === null) {
                    $firstConnection = $connection;
                } else {
                    $this->channel->push($connection);
                }

                $required--;
            }
        } finally {
            $this->creationLock->push($lock);
        }

        return [$firstConnection, $creationException];
    }

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
        $idle = (int) ($stats['queue_num'] ?? 0);
        $waiters = (int) ($stats['consumer_num'] ?? 0);

        return [
            'created' => $this->created,
            'idle' => $idle,
            'in_use' => max(0, $this->created - $idle),
            'waiters' => $waiters,
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

        $this->created++;

        return $connection;
    }
}
