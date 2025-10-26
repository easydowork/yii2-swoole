<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Cache;

use Dacheng\Yii2\Swoole\Redis\CoroutineRedisConnection;
use yii\di\Instance;
use yii\redis\Cache as BaseCache;

/**
 * CoroutineRedisCache implements a coroutine-safe cache backend based on Redis.
 * 
 * This cache component extends yii2-redis Cache and uses CoroutineRedisConnection
 * to provide efficient connection pooling in Swoole coroutine environments.
 * 
 * Configuration example:
 * 
 * ```php
 * [
 *     'components' => [
 *         'cache' => [
 *             'class' => 'Dacheng\Yii2\Swoole\Cache\CoroutineRedisCache',
 *             'redis' => [
 *                 'class' => 'Dacheng\Yii2\Swoole\Redis\CoroutineRedisConnection',
 *                 'hostname' => 'localhost',
 *                 'port' => 6379,
 *                 'database' => 0,
 *                 'poolMaxActive' => 32,
 *                 'poolWaitTimeout' => 3.0,
 *             ]
 *         ],
 *     ],
 * ]
 * ```
 * 
 * Or if you have configured the coroutine redis connection as an application component:
 * 
 * ```php
 * [
 *     'components' => [
 *         'cache' => [
 *             'class' => 'Dacheng\Yii2\Swoole\Cache\CoroutineRedisCache',
 *             'redis' => 'redis', // id of the coroutine connection component
 *         ],
 *     ],
 * ]
 * ```
 * 
 * For replica support in coroutine environments:
 * 
 * ```php
 * [
 *     'components' => [
 *         'cache' => [
 *             'class' => 'Dacheng\Yii2\Swoole\Cache\CoroutineRedisCache',
 *             'enableReplicas' => true,
 *             'replicas' => [
 *                 [
 *                     'class' => 'Dacheng\Yii2\Swoole\Redis\CoroutineRedisConnection',
 *                     'hostname' => 'redis-replica-1.local',
 *                 ],
 *                 [
 *                     'class' => 'Dacheng\Yii2\Swoole\Redis\CoroutineRedisConnection',
 *                     'hostname' => 'redis-replica-2.local',
 *                 ],
 *             ],
 *         ],
 *     ],
 * ]
 * ```
 * 
 * All cache operations are performed through the coroutine connection pool,
 * ensuring efficient resource utilization in high-concurrency scenarios.
 */
class CoroutineRedisCache extends BaseCache
{
    /**
     * @var CoroutineRedisConnection|string|array the coroutine Redis connection
     */
    public $redis = 'redis';

    /**
     * Initializes the cache component.
     * Ensures the redis connection is a valid CoroutineRedisConnection instance.
     */
    public function init()
    {
        parent::init();
        $this->redis = Instance::ensure($this->redis, CoroutineRedisConnection::class);
    }

    /**
     * Returns the current replica connection or the main connection.
     * Ensures replicas are also coroutine connections.
     * 
     * @return CoroutineRedisConnection
     */
    protected function getReplica()
    {
        if ($this->enableReplicas === false) {
            return $this->redis;
        }

        $replica = parent::getReplica();
        
        // Ensure replica is a coroutine connection
        if (!($replica instanceof CoroutineRedisConnection)) {
            throw new \yii\base\InvalidConfigException(
                'Replicas must be instances of CoroutineRedisConnection when using CoroutineRedisCache'
            );
        }

        return $replica;
    }

    /**
     * Returns the Redis connection pool statistics.
     * 
     * @return array{created: int, idle: int, in_use: int, waiters: int, capacity: int}
     */
    public function getPoolStats(): array
    {
        return $this->redis->getPoolStats();
    }
}

