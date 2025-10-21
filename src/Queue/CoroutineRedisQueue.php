<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Queue;

use Dacheng\Yii2\Swoole\Redis\CoroutineRedisConnection;
use yii\redis\Connection;
use Swoole\Coroutine;
use yii\base\InvalidArgumentException;
use yii\base\NotSupportedException;
use yii\di\Instance;
use yii\queue\cli\Queue as CliQueue;
use yii\queue\interfaces\StatisticsProviderInterface;

/**
 * Coroutine Redis Queue for Swoole.
 * 
 * This queue driver is optimized for Swoole coroutine environments.
 * It uses coroutine-safe Redis connections and supports concurrent job processing.
 * 
 * Configuration example:
 * ```php
 * 'queue' => [
 *     'class' => \Dacheng\Yii2\Swoole\Queue\CoroutineRedisQueue::class,
 *     'redis' => 'redis', // CoroutineRedisConnection component
 *     'channel' => 'queue',
 * ],
 * ```
 * 
 * @property-read CoroutineRedisStatisticsProvider $statisticsProvider
 */
class CoroutineRedisQueue extends CliQueue implements StatisticsProviderInterface
{
    /**
     * @var Connection|CoroutineRedisConnection|array|string
     */
    public $redis = 'redis';
    
    /**
     * @var string Redis key prefix for queue data
     */
    public $channel = 'queue';
    
    /**
     * @var string command class name
     */
    public $commandClass = CoroutineRedisCommand::class;
    
    /**
     * @var array Loop configuration. Use CoroutineLoop to avoid signal handling conflicts.
     * The default SignalLoop uses pcntl_signal() which conflicts with Swoole's Process::signal().
     * We handle signals in CoroutineRedisCommand instead.
     */
    public $loopConfig = ['class' => CoroutineLoop::class];
    
    /**
     * @var bool Whether to execute jobs in the same process (faster) or fork child processes (more isolated).
     * Default is true for better performance in coroutine context.
     */
    public $executeInline = true;
    
    /**
     * @var int Number of concurrent coroutines for job processing.
     * Higher values allow more parallel job execution, improving CPU utilization.
     * Set to 1 for serial processing (default for compatibility).
     * Recommended: 10-50 for I/O intensive jobs, 1-10 for CPU intensive jobs.
     */
    public $concurrency = 10;

    /**
     * @var callable|null Callback that returns true if shutdown is requested
     */
    private $shutdownCallback = null;

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();
        $this->redis = Instance::ensure($this->redis, Connection::class);
    }

    /**
     * Sets a callback to check if shutdown is requested
     * 
     * @param callable $callback Callback that returns true if shutdown is requested
     */
    public function setShutdownCallback(callable $callback): void
    {
        $this->shutdownCallback = $callback;
    }

    /**
     * Checks if shutdown has been requested
     * 
     * @return bool
     */
    protected function isShutdownRequested(): bool
    {
        if ($this->shutdownCallback === null) {
            return false;
        }
        
        return (bool) call_user_func($this->shutdownCallback);
    }

    /**
     * Listens queue and runs each job.
     * 
     * This method is optimized for Swoole coroutine environments.
     * It properly handles coroutine context and connection pooling.
     *
     * @param bool $repeat whether to continue listening when queue is empty.
     * @param int $timeout number of seconds to wait for next message.
     * @return null|int exit code.
     * @internal for worker command only.
     */
    public function run($repeat, $timeout = 0)
    {
        // Use concurrent processing if concurrency > 1 and in coroutine context
        if ($this->concurrency > 1 && Coroutine::getCid() >= 0) {
            return $this->runConcurrent($repeat, $timeout);
        }
        
        // Fallback to serial processing
        \Yii::info("Using serial processing mode (concurrency={$this->concurrency})", __METHOD__);
        
        return $this->runWorker(function (callable $canContinue) use ($repeat, $timeout) {
            // Open connection once for the entire worker session
            $this->redis->open();
            
            try {
                $jobCount = 0;
                $startTime = microtime(true);
                
                \Yii::info("Serial worker loop starting", __METHOD__);
                
                while ($canContinue() && !$this->isShutdownRequested()) {
                    try {
                        if (($payload = $this->reserve($timeout)) !== null) {
                            list($id, $message, $ttr, $attempt) = $payload;
                            if ($this->handleMessage($id, $message, $ttr, $attempt)) {
                                $this->delete($id);
                            }
                            $jobCount++;
                        } elseif (!$repeat) {
                            break;
                        }
                        
                        // Check for shutdown after each job
                        if ($this->isShutdownRequested()) {
                            $duration = round(microtime(true) - $startTime, 2);
                            $rate = $duration > 0 ? round($jobCount / $duration, 2) : 0;
                            \Yii::info("Shutdown requested, stopping worker after current job. Processed {$jobCount} jobs in {$duration}s ({$rate} jobs/s)", __METHOD__);
                            error_log("[Queue] Breaking from serial worker loop due to shutdown");
                            break;
                        }
                    } catch (\yii\redis\SocketException $e) {
                        // Connection lost during blocking operation, reconnect
                        $this->redis->close();
                        $this->redis->open();
                        
                        // If we're in listen mode, continue; otherwise break
                        if (!$repeat) {
                            break;
                        }
                    }
                }
                
                error_log("[Queue] Exited serial worker loop");
            } finally {
                // Return connection to pool when worker stops
                $this->redis->close();
                error_log("[Queue] Serial worker cleanup complete");
            }
        });
    }
    
    /**
     * Runs queue with concurrent job processing using coroutines.
     * 
     * Architecture:
     * - Producer coroutine: fetches jobs from Redis and sends to job channel
     * - Worker coroutines: process jobs concurrently and send completed IDs to result channel
     * - Deleter coroutine: receives completed IDs and deletes from Redis
     * 
     * This design minimizes Redis connections (only 2) while maximizing concurrency.
     * 
     * @param bool $repeat whether to continue listening when queue is empty.
     * @param int $timeout number of seconds to wait for next message.
     * @return null|int exit code.
     */
    protected function runConcurrent($repeat, $timeout = 0)
    {
        return $this->runWorker(function (callable $canContinue) use ($repeat, $timeout) {
            // Channels for communication between coroutines
            $jobChannel = new \Swoole\Coroutine\Channel($this->concurrency * 2);  // Job queue
            $resultChannel = new \Swoole\Coroutine\Channel($this->concurrency * 2);  // Completed job IDs
            $channelsClosed = false;  // Track if channels have been closed
            
            $activeWorkers = 0;
            $shouldStop = false;
            $producerDone = false;
            $deleterDone = false;
            $processedCount = 0;
            $startTime = microtime(true);
            
            // Producer coroutine: fetches jobs from Redis
            Coroutine::create(function () use ($jobChannel, &$shouldStop, $canContinue, $repeat, $timeout, &$producerDone) {
                $this->redis->open();
                
                try {
                    while ($canContinue() && !$shouldStop && !$this->isShutdownRequested()) {
                        try {
                            if (($payload = $this->reserve($timeout)) !== null) {
                                // Send job to worker pool
                                $jobChannel->push($payload);
                            } elseif (!$repeat) {
                                $shouldStop = true;
                                break;
                            }
                            
                            // Check for shutdown signal
                            if ($this->isShutdownRequested()) {
                                \Yii::info('Shutdown requested, stopping producer', __METHOD__);
                                $shouldStop = true;
                                break;
                            }
                        } catch (\yii\redis\SocketException $e) {
                            $this->redis->close();
                            $this->redis->open();
                            
                            if (!$repeat) {
                                $shouldStop = true;
                                break;
                            }
                        }
                    }
                } finally {
                    $this->redis->close();
                    $producerDone = true;
                    
                    // Signal workers to stop
                    for ($i = 0; $i < $this->concurrency; $i++) {
                        $jobChannel->push(null);
                    }
                }
            });
            
            // Worker coroutines: process jobs concurrently
            for ($i = 0; $i < $this->concurrency; $i++) {
                Coroutine::create(function () use ($jobChannel, $resultChannel, &$activeWorkers, &$processedCount, &$shouldStop, $i) {
                    $activeWorkers++;
                    \Yii::info("Worker #{$i}: started", __METHOD__);
                    
                    try {
                        while (true) {
                            // Check for shutdown before blocking on pop
                            if ($this->isShutdownRequested() || $shouldStop) {
                                \Yii::info("Worker #{$i}: shutdown requested, exiting", __METHOD__);
                                break;
                            }
                            
                            // Use timeout to allow checking shutdown periodically
                            $payload = $jobChannel->pop(1.0);
                            
                            // false means timeout or channel closed
                            if ($payload === false) {
                                // Check if we should continue waiting
                                if ($this->isShutdownRequested() || $shouldStop) {
                                    \Yii::info("Worker #{$i}: shutdown detected, exiting", __METHOD__);
                                    break;
                                }
                                // Also check if channel is closed by trying to get stats
                                $stats = @$jobChannel->stats();
                                if ($stats === false) {
                                    \Yii::info("Worker #{$i}: channel closed, exiting", __METHOD__);
                                    break;
                                }
                                continue; // Timeout, try again
                            }
                            
                            // null means stop signal
                            if ($payload === null) {
                                \Yii::info("Worker #{$i}: received stop signal", __METHOD__);
                                break;
                            }
                            
                            list($id, $message, $ttr, $attempt) = $payload;
                            
                            try {
                                // Process the job
                                $success = $this->handleMessage($id, $message, $ttr, $attempt);
                                
                                // Send result to deleter
                                if ($success) {
                                    $resultChannel->push($id);
                                    $processedCount++;
                                }
                            } catch (\Throwable $e) {
                                // Log error but don't crash the worker
                                \Yii::error("Worker #{$i}: Job #{$id} failed: " . $e->getMessage(), __METHOD__);
                            }
                        }
                    } finally {
                        $activeWorkers--;
                        \Yii::info("Worker #{$i}: exited, activeWorkers now: {$activeWorkers}", __METHOD__);
                    }
                });
            }
            
            // Deleter coroutine: receives completed job IDs and deletes from Redis
            Coroutine::create(function () use ($resultChannel, &$activeWorkers, &$producerDone, &$deleterDone, &$shouldStop) {
                // Create a separate Redis connection for deletion
                // This avoids connection conflicts with the producer coroutine
                $redis = $this->createRedisConnection();
                $redis->open();
                
                try {
                    // Keep processing results until all workers are done
                    while (true) {
                        // Check for shutdown first
                        if ($this->isShutdownRequested() || $shouldStop) {
                            \Yii::info("Deleter: shutdown requested, exiting", __METHOD__);
                            break;
                        }
                        
                        // Use pop with timeout to avoid blocking forever
                        $id = $resultChannel->pop(0.1);
                        
                        if ($id !== false) {
                            // Delete the job from Redis
                            try {
                                $this->deleteWithConnection($redis, $id);
                            } catch (\Throwable $e) {
                                \Yii::error("Failed to delete job #{$id}: " . $e->getMessage(), __METHOD__);
                            }
                        }
                        
                        // Exit when producer is done and all workers finished
                        if ($producerDone && $activeWorkers === 0) {
                            \Yii::info("Deleter: producer done and no active workers, draining results", __METHOD__);
                            // Drain remaining results quickly
                            $drained = 0;
                            while (($id = $resultChannel->pop(0.01)) !== false && $drained < 1000) {
                                try {
                                    $this->deleteWithConnection($redis, $id);
                                    $drained++;
                                } catch (\Throwable $e) {
                                    \Yii::error("Failed to delete job #{$id}: " . $e->getMessage(), __METHOD__);
                                }
                            }
                            \Yii::info("Deleter: drained {$drained} results, exiting", __METHOD__);
                            break;
                        }
                    }
                } catch (\Throwable $e) {
                    \Yii::error("Deleter error: " . $e->getMessage(), __METHOD__);
                } finally {
                    $redis->close();
                    $deleterDone = true;
                    \Yii::info("Deleter: finally block, deleterDone set to true", __METHOD__);
                }
            });
            
            // Wait for all coroutines to finish
            $shutdownTimeout = 5.0; // Maximum time to wait for graceful shutdown (reduced from 10s)
            $shutdownStartTime = microtime(true);
            $forcedShutdown = false;
            
            while (!$producerDone || $activeWorkers > 0 || !$deleterDone) {
                Coroutine::sleep(0.1);
                
                // Debug: Log current state
                if ($this->isShutdownRequested()) {
                    $elapsed = microtime(true) - $shutdownStartTime;
                    \Yii::info(sprintf(
                        "[Wait Loop] Elapsed: %.1fs, Producer: %s, Workers: %d, Deleter: %s",
                        $elapsed,
                        $producerDone ? 'done' : 'running',
                        $activeWorkers,
                        $deleterDone ? 'done' : 'running'
                    ), __METHOD__);
                }
                
                // Force exit if shutdown requested and producer is done
                if ($this->isShutdownRequested() && $producerDone) {
                    // Give workers a bit more time to finish
                    $maxWait = 2.0; // 2 seconds max (reduced from 5s)
                    $waited = 0.0;
                    while ($activeWorkers > 0 && $waited < $maxWait) {
                        Coroutine::sleep(0.1);
                        $waited += 0.1;
                    }
                    
                    // Force break if workers still not done
                    if ($activeWorkers > 0) {
                        \Yii::warning("Forcing shutdown with {$activeWorkers} workers still active", __METHOD__);
                    }
                    
                    // Close channels immediately to wake up deleter
                    if (!$deleterDone && !$channelsClosed) {
                        \Yii::warning("Forcing shutdown - closing channels to wake deleter", __METHOD__);
                        try {
                            $jobChannel->close();
                            $resultChannel->close();
                            $channelsClosed = true;
                        } catch (\Throwable $e) {
                            \Yii::error("Error closing channels: " . $e->getMessage(), __METHOD__);
                        }
                        
                        // Give deleter a moment to exit
                        Coroutine::sleep(0.2);
                        
                        if (!$deleterDone) {
                            \Yii::warning("Deleter still not done after closing channels", __METHOD__);
                        }
                    }
                    
                    $forcedShutdown = true;
                    break;
                }
                
                // Safety timeout to prevent infinite waiting
                $elapsed = microtime(true) - $shutdownStartTime;
                if ($elapsed >= $shutdownTimeout) {
                    \Yii::warning(sprintf(
                        "Shutdown timeout reached after %.1fs, forcing exit (producer: %s, workers: %d, deleter: %s)",
                        $elapsed,
                        $producerDone ? 'done' : 'running',
                        $activeWorkers,
                        $deleterDone ? 'done' : 'running'
                    ), __METHOD__);
                    
                    // Close channels to force coroutines to exit
                    if (!$channelsClosed) {
                        try {
                            $jobChannel->close();
                            $resultChannel->close();
                            $channelsClosed = true;
                        } catch (\Throwable $e) {
                            \Yii::error("Error closing channels on timeout: " . $e->getMessage(), __METHOD__);
                        }
                    }
                    $forcedShutdown = true;
                    break;
                }
            }
            
            // Close channels if not already closed
            if (!$channelsClosed) {
                try {
                    $jobChannel->close();
                    $resultChannel->close();
                    $channelsClosed = true;
                } catch (\Throwable $e) {
                    \Yii::error("Error closing channels at end: " . $e->getMessage(), __METHOD__);
                }
            }
            
            // Output statistics
            $duration = round(microtime(true) - $startTime, 2);
            $rate = $duration > 0 ? round($processedCount / $duration, 2) : 0;
            
            if ($this->isShutdownRequested()) {
                \Yii::info("Queue worker shutdown: {$processedCount} jobs processed in {$duration}s ({$rate} jobs/s)", __METHOD__);
            } else {
                \Yii::info("Queue worker finished: {$processedCount} jobs processed in {$duration}s ({$rate} jobs/s)", __METHOD__);
            }
            
            // Final coroutine check
            $finalStats = Coroutine::stats();
            \Yii::info("Coroutines at end of runConcurrent: {$finalStats['coroutine_num']}", __METHOD__);
        });
    }

    /**
     * @inheritdoc
     */
    public function status($id)
    {
        if (!is_numeric($id) || $id <= 0) {
            throw new InvalidArgumentException("Unknown message ID: $id.");
        }

        $this->redis->open();
        try {
            if ($this->redis->hexists("$this->channel.attempts", $id)) {
                return self::STATUS_RESERVED;
            }

            if ($this->redis->hexists("$this->channel.messages", $id)) {
                return self::STATUS_WAITING;
            }

            return self::STATUS_DONE;
        } finally {
            $this->redis->close();
        }
    }

    /**
     * Clears the queue.
     * 
     * WARNING: This operation will delete all jobs in the queue,
     * including waiting, delayed, and reserved jobs.
     */
    public function clear()
    {
        $this->redis->open();
        try {
            // Try to acquire lock with retries (max 100ms)
            $retries = 10;
            $lockAcquired = false;
            while (!($lockAcquired = $this->redis->set("$this->channel.moving_lock", true, 'NX', 'EX', 1)) && $retries-- > 0) {
                usleep(10000);
            }
            
            if (!$lockAcquired) {
                \Yii::warning("Could not acquire lock to clear queue, proceeding anyway", __METHOD__);
            }
            
            $keys = $this->redis->keys("$this->channel.*");
            if (!empty($keys)) {
                $this->redis->executeCommand('DEL', $keys);
                \Yii::info("Cleared " . count($keys) . " keys from queue", __METHOD__);
            }
        } finally {
            $this->redis->close();
        }
    }

    /**
     * Removes a job by ID.
     *
     * @param int $id of a job
     * @return bool
     */
    public function remove($id)
    {
        $this->redis->open();
        try {
            // Try to acquire lock with retries (max 100ms)
            $retries = 10;
            while (!$this->redis->set("$this->channel.moving_lock", true, 'NX', 'EX', 1) && $retries-- > 0) {
                usleep(10000);
            }
            
            if ($this->redis->hdel("$this->channel.messages", $id)) {
                $this->redis->zrem("$this->channel.delayed", $id);
                $this->redis->zrem("$this->channel.reserved", $id);
                $this->redis->lrem("$this->channel.waiting", 0, $id);
                $this->redis->hdel("$this->channel.attempts", $id);

                return true;
            }

            return false;
        } finally {
            $this->redis->close();
        }
    }

    /**
     * Reserves a job from the queue.
     * 
     * @param int $timeout timeout in seconds
     * @return array|null payload [id, message, ttr, attempt]
     */
    protected function reserve($timeout)
    {
        // Moves delayed and reserved jobs into waiting list with lock for one second
        if ($this->redis->set("$this->channel.moving_lock", true, 'NX', 'EX', 1)) {
            $this->moveExpired("$this->channel.delayed");
            $this->moveExpired("$this->channel.reserved");
        }

        // Find a new waiting message
        $id = null;
        if (!$timeout) {
            $id = $this->redis->rpop("$this->channel.waiting");
        } else {
            // Use shorter timeout intervals to allow checking for shutdown
            // Break long timeout into 1-second chunks
            $maxTimeout = 1; // Check for shutdown every 1 second
            $elapsed = 0;
            
            while ($elapsed < $timeout) {
                // Check for shutdown before blocking
                if ($this->isShutdownRequested()) {
                    return null;
                }
                
                $remainingTimeout = min($maxTimeout, $timeout - $elapsed);
                $result = $this->redis->brpop("$this->channel.waiting", $remainingTimeout);
                
                if ($result) {
                    $id = $result[1];
                    break;
                }
                
                $elapsed += $remainingTimeout;
            }
        }
        
        if (!$id) {
            return null;
        }

        $payload = $this->redis->hget("$this->channel.messages", $id);
        if (null === $payload) {
            return null;
        }

        list($ttr, $message) = explode(';', $payload, 2);
        $this->redis->zadd("$this->channel.reserved", time() + $ttr, $id);
        $attempt = $this->redis->hincrby("$this->channel.attempts", $id, 1);

        return [$id, $message, $ttr, $attempt];
    }

    /**
     * Moves expired jobs from a sorted set to the waiting list.
     * 
     * @param string $from Redis key of the sorted set
     */
    protected function moveExpired($from)
    {
        $now = time();
        if ($expired = $this->redis->zrevrangebyscore($from, $now, '-inf')) {
            $this->redis->zremrangebyscore($from, '-inf', $now);
            foreach ($expired as $id) {
                $this->redis->rpush("$this->channel.waiting", $id);
            }
        }
    }

    /**
     * Deletes message by ID.
     *
     * @param int $id of a message
     */
    protected function delete($id)
    {
        $this->redis->zrem("$this->channel.reserved", $id);
        $this->redis->hdel("$this->channel.attempts", $id);
        $this->redis->hdel("$this->channel.messages", $id);
    }
    
    /**
     * Deletes message by ID using a specific Redis connection.
     * Used in concurrent processing to avoid connection conflicts.
     *
     * @param \yii\redis\Connection $redis Redis connection to use
     * @param int $id of a message
     */
    protected function deleteWithConnection($redis, $id)
    {
        $redis->zrem("$this->channel.reserved", $id);
        $redis->hdel("$this->channel.attempts", $id);
        $redis->hdel("$this->channel.messages", $id);
    }
    
    /**
     * @inheritdoc
     * 
     * Override to support inline execution (without forking child processes).
     * This is much faster for simple jobs and works well in coroutine context.
     */
    protected function handleMessage($id, $message, $ttr, $attempt)
    {
        if ($this->executeInline) {
            // Execute in same process (faster, no fork overhead)
            return parent::handleMessage($id, $message, $ttr, $attempt);
        }
        
        // Use parent implementation which may fork child processes
        return parent::handleMessage($id, $message, $ttr, $attempt);
    }

    /**
     * @inheritdoc
     */
    protected function pushMessage($message, $ttr, $delay, $priority)
    {
        if ($priority !== null) {
            throw new NotSupportedException('Job priority is not supported in the driver.');
        }

        $this->redis->open();
        try {
            $id = $this->redis->incr("$this->channel.message_id");
            $this->redis->hset("$this->channel.messages", $id, "$ttr;$message");
            
            if (!$delay) {
                $this->redis->lpush("$this->channel.waiting", $id);
            } else {
                $this->redis->zadd("$this->channel.delayed", time() + $delay, $id);
            }

            return $id;
        } finally {
            $this->redis->close();
        }
    }

    /**
     * Creates a new Redis connection instance with the same configuration.
     * This is used in concurrent processing to create dedicated connections.
     * 
     * @return Connection|CoroutineRedisConnection
     */
    protected function createRedisConnection()
    {
        $redisConfig = [];
        
        if (is_string($this->redis)) {
            $redisConfig = \Yii::$app->components[$this->redis] ?? [];
        } elseif (is_array($this->redis)) {
            $redisConfig = $this->redis;
        } else {
            // Clone configuration from existing connection object
            $redisConfig = ['class' => get_class($this->redis)];
            $properties = ['hostname', 'port', 'database', 'password', 'username', 
                          'poolMaxActive', 'poolWaitTimeout', 'connectionTimeout', 'dataTimeout'];
            foreach ($properties as $prop) {
                if (property_exists($this->redis, $prop)) {
                    $redisConfig[$prop] = $this->redis->$prop;
                }
            }
        }
        
        return \Yii::createObject($redisConfig);
    }

    private $_statisticsProvider;

    /**
     * @return CoroutineRedisStatisticsProvider
     */
    public function getStatisticsProvider()
    {
        if (!$this->_statisticsProvider) {
            $this->_statisticsProvider = new CoroutineRedisStatisticsProvider($this);
        }
        return $this->_statisticsProvider;
    }
}