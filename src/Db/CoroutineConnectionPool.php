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

    private int $idle = 0;

    private int $waiters = 0;

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
            $this->pushConnection($this->createConnection());
        }

        $this->idle = $this->minActive;
    }

    public function acquire(): PDO
    {
        $connection = $this->channel->pop(0.0);
        if ($connection instanceof PDO) {
            $this->decrementIdle();

            return $connection;
        }

        $creationException = null;

        if ($this->created < $this->maxActive) {
            $availableSlots = $this->maxActive - $this->created;
            [$newConnection, $creationException] = $this->growPool($availableSlots, true);

            if ($newConnection instanceof PDO) {
                return $newConnection;
            }
        }

        $this->waiters++;

        try {
            $connection = $this->channel->pop($this->waitTimeout);
        } finally {
            $this->waiters = max(0, $this->waiters - 1);
        }

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

        $this->decrementIdle();

        return $connection;
    }

    public function release(PDO $connection): void
    {
        $this->pushConnection($connection);
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
                    if (!$this->pushConnection($connection)) {
                        break;
                    }
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
        return [
            'created' => $this->created,
            'idle' => $this->idle,
            'in_use' => max(0, $this->created - $this->idle),
            'waiters' => $this->waiters,
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

    private function pushConnection(PDO $connection): bool
    {
        $deliversToWaiter = $this->waiters > 0;

        if (!$this->channel->push($connection, 0.0)) {
            $this->created = max(0, $this->created - 1);
            $this->closeConnection($connection);

            return false;
        }

        if (!$deliversToWaiter) {
            $this->idle++;
        }

        return true;
    }

    private function decrementIdle(): void
    {
        if ($this->idle > 0) {
            $this->idle--;
        }
    }
}
