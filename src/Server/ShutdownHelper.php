<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Server;

use Dacheng\Yii2\Swoole\Db\CoroutineDbConnection;
use Dacheng\Yii2\Swoole\Log\LogWorker;
use Dacheng\Yii2\Swoole\Redis\CoroutineRedisConnection;
use Swoole\Coroutine;
use Yii;

/**
 * ShutdownHelper provides unified shutdown operations for graceful application termination.
 * 
 * This helper consolidates common shutdown tasks:
 * - Flushing log messages and stopping log workers
 * - Closing database and Redis connection pools
 * 
 * It prevents code duplication across HttpServer, Queue workers, and other components.
 */
class ShutdownHelper
{
    /**
     * Flushes all pending log messages and stops log workers
     * 
     * This performs a multi-step flush:
     * 1. Exports remaining messages from logger to targets
     * 2. Stops log workers (which flush their buffers to disk)
     * 3. Shuts down all log targets cleanly
     * 
     * @param bool $verbose Whether to output progress messages to error_log
     */
    public static function flushLogs(bool $verbose = true): void
    {
        if (!Yii::$app->has('log')) {
            return;
        }

        try {
            if ($verbose) {
                error_log('[ShutdownHelper] Flushing logs...');
            }

            // Step 1: Export any remaining messages from logger to targets
            $logger = Yii::$app->log->getLogger();
            if (!empty($logger->messages)) {
                $messageCount = count($logger->messages);
                if ($verbose) {
                    error_log("[ShutdownHelper] Exporting {$messageCount} remaining log messages");
                }
                Yii::$app->log->flush(true);
                
                // Give a short time for messages to be processed
                if (Coroutine::getCid() > 0) {
                    Coroutine::sleep(0.05);
                } else {
                    usleep(50000);
                }
            }
            
            // Step 2: Stop log workers (this will flush messages from buffer to disk)
            foreach (Yii::$app->log->targets as $targetName => $target) {
                try {
                    if (method_exists($target, 'getWorker')) {
                        $worker = $target->getWorker();
                        if ($worker instanceof LogWorker) {
                            if ($verbose) {
                                error_log("[ShutdownHelper] Stopping log worker for target: {$targetName}");
                            }
                            $worker->stop();
                        }
                    }
                } catch (\Throwable $e) {
                    error_log("[ShutdownHelper] Error stopping log worker '{$targetName}': {$e->getMessage()}");
                }
            }

            // Step 3: Shutdown all targets cleanly
            foreach (Yii::$app->log->targets as $targetName => $target) {
                if (method_exists($target, 'shutdown')) {
                    try {
                        $target->shutdown();
                    } catch (\Throwable $e) {
                        error_log("[ShutdownHelper] Error shutting down log target '{$targetName}': {$e->getMessage()}");
                    }
                }
            }
            
            if ($verbose) {
                error_log('[ShutdownHelper] Log flush complete');
            }
        } catch (\Throwable $e) {
            error_log('[ShutdownHelper] Error flushing logs: ' . $e->getMessage());
        }
    }

    /**
     * Closes all database and Redis connection pools
     * 
     * @param bool $verbose Whether to output progress messages to error_log
     */
    public static function closeConnectionPools(bool $verbose = true): void
    {
        if ($verbose) {
            error_log('[ShutdownHelper] Closing connection pools...');
        }
        
        try {
            CoroutineDbConnection::shutdownAllPools();
        } catch (\Throwable $e) {
            error_log('[ShutdownHelper] Error closing DB pools: ' . $e->getMessage());
        }
        
        try {
            CoroutineRedisConnection::shutdownAllPools();
        } catch (\Throwable $e) {
            error_log('[ShutdownHelper] Error closing Redis pools: ' . $e->getMessage());
        }

        if ($verbose) {
            error_log('[ShutdownHelper] Connection pools closed');
        }
    }

    /**
     * Performs complete graceful shutdown sequence
     * 
     * This executes both log flushing and connection pool closing
     * in the correct order.
     * 
     * @param bool $verbose Whether to output progress messages to error_log
     */
    public static function performGracefulShutdown(bool $verbose = true): void
    {
        if ($verbose) {
            error_log('[ShutdownHelper] Starting graceful shutdown sequence...');
        }

        self::flushLogs($verbose);
        self::closeConnectionPools($verbose);

        if ($verbose) {
            error_log('[ShutdownHelper] Graceful shutdown sequence complete');
        }
    }
}

