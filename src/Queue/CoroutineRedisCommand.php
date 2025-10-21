<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Queue;

use Dacheng\Yii2\Swoole\Db\CoroutineDbConnection;
use Dacheng\Yii2\Swoole\Log\LogWorker;
use Dacheng\Yii2\Swoole\Redis\CoroutineRedisConnection;
use Swoole\Coroutine;
use Swoole\Process;
use yii\console\Exception;
use yii\queue\cli\Command as CliCommand;
use yii\queue\cli\InfoAction;

/**
 * Manages application coroutine redis-queue with Swoole support.
 * 
 * This command relies on CoroutineApplication to provide the coroutine environment.
 * When run via the console entry point, coroutines are automatically enabled.
 */
class CoroutineRedisCommand extends CliCommand
{
    /**
     * @var CoroutineRedisQueue
     */
    public $queue;
    
    /**
     * @var string
     */
    public $defaultAction = 'info';
    
    /**
     * @var bool Whether to isolate job execution in child processes.
     * Default is false for better performance in coroutine context.
     * Set to true if you need job isolation (at the cost of performance).
     */
    public $isolate = false;

    /**
     * @var bool Whether shutdown has been requested via signal
     */
    private $shutdownRequested = false;

    /**
     * @inheritdoc
     */
    public function actions()
    {
        return [
            'info' => InfoAction::class,
        ];
    }

    /**
     * @inheritdoc
     */
    protected function isWorkerAction($actionID)
    {
        return in_array($actionID, ['run', 'listen'], true);
    }

    /**
     * Runs all jobs from redis-queue.
     * It can be used as cron job.
     * 
     * Unlike listen, this command processes all waiting jobs and then exits.
     * This is useful for running as a scheduled task (cron job).
     *
     * @return null|int exit code.
     */
    public function actionRun()
    {
        $this->registerSignalHandlers();
        
        $this->stdout("Processing all waiting jobs...\n");
        $this->printCoroutineInfo();
        
        $result = $this->queue->run(false, 0);
        
        if ($this->shutdownRequested) {
            $this->stdout("\nShutdown requested, stopping after current jobs...\n");
        }
        
        $this->stdout("All jobs processed.\n");
        $this->performGracefulShutdown();
        
        return $result;
    }

    /**
     * Listens redis-queue and runs new jobs with Swoole coroutine support.
     * 
     * This action runs in a Swoole coroutine context (provided by CoroutineApplication),
     * enabling efficient concurrent job processing with connection pooling.
     *
     * @param int $timeout number of seconds to wait for a job.
     * @throws Exception when params are invalid.
     * @return null|int exit code.
     */
    public function actionListen($timeout = 3)
    {
        if (!is_numeric($timeout)) {
            throw new Exception('Timeout must be numeric.');
        }
        if ($timeout < 1) {
            throw new Exception('Timeout must be greater than zero.');
        }

        $this->registerSignalHandlers();

        $this->stdout("========================================\n");
        $this->stdout("Queue Worker Configuration\n");
        $this->stdout("========================================\n");
        $this->printCoroutineInfo();
        $this->stdout("Concurrency level: {$this->queue->concurrency}\n");
        $this->stdout("Execute inline: " . ($this->queue->executeInline ? 'YES' : 'NO') . "\n");
        $this->stdout("Timeout: {$timeout}s\n");
        $this->stdout("========================================\n\n");
        $this->stdout("Listening for jobs...\n");
        $this->stdout("Press Ctrl+C or send SIGTERM to gracefully shutdown\n\n");

        // Set shutdown callback for the queue
        $this->queue->setShutdownCallback(function() {
            return $this->shutdownRequested;
        });

        $result = $this->queue->run(true, $timeout);
        
        if ($this->shutdownRequested) {
            $this->stdout("\nGraceful shutdown initiated...\n");
            
            // Disable further logging during shutdown to prevent loops
            if (\Yii::$app->has('log')) {
                \Yii::$app->log->flushInterval = PHP_INT_MAX;
            }
            
            $this->performGracefulShutdown();
            
            // Wait for remaining coroutines with timeout
            if (extension_loaded('swoole')) {
                $stats = Coroutine::stats();
                
                if ($stats['coroutine_num'] > 1) {
                    $maxWait = 2.0;
                    $waited = 0.0;
                    
                    while ($stats['coroutine_num'] > 1 && $waited < $maxWait) {
                        Coroutine::sleep(0.1);
                        $waited += 0.1;
                        $stats = Coroutine::stats();
                    }
                    
                    // If coroutines still exist, set up safety timer as last resort
                    if ($stats['coroutine_num'] > 1) {
                        \Swoole\Timer::after(2000, function() {
                            posix_kill(getmypid(), SIGKILL);
                        });
                    }
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Prints coroutine environment information
     */
    protected function printCoroutineInfo(): void
    {
        if (!extension_loaded('swoole')) {
            $this->stdout("Swoole support: NOT AVAILABLE (running in standard mode)\n");
            return;
        }

        $cid = Coroutine::getCid();
        
        if ($cid > 0) {
            $stats = Coroutine::stats();
            $this->stdout("Swoole coroutine: ENABLED (ID: {$cid})\n");
            $this->stdout("Active coroutines: {$stats['coroutine_num']}\n");
        } else {
            $this->stdout("Swoole coroutine: AVAILABLE (not in coroutine context)\n");
        }
    }

    /**
     * Clears the queue.
     */
    public function actionClear()
    {
        if ($this->confirm('Are you sure?')) {
            $this->queue->clear();
            $this->stdout("Queue cleared.\n");
        }
    }

    /**
     * Removes a job by id.
     *
     * @param int $id
     * @throws Exception when the job is not found.
     */
    public function actionRemove($id)
    {
        if (!$this->queue->remove($id)) {
            throw new Exception('The job is not found.');
        }
        $this->stdout("Job #{$id} removed.\n");
    }

    /**
     * Registers signal handlers for graceful shutdown
     */
    protected function registerSignalHandlers(): void
    {
        if (!extension_loaded('pcntl')) {
            $this->stdout("[Warning] PCNTL extension not loaded, signal handling disabled\n");
            return;
        }

        // Use Swoole's Process::signal for coroutine-safe signal handling
        Process::signal(SIGTERM, function () {
            $this->stdout("\n[Signal] Received SIGTERM, initiating graceful shutdown...\n");
            $this->shutdownRequested = true;
            
            // Stop the loop immediately
            \Dacheng\Yii2\Swoole\Queue\CoroutineLoop::stop();
        });

        Process::signal(SIGINT, function () {
            $this->stdout("\n[Signal] Received SIGINT (Ctrl+C), initiating graceful shutdown...\n");
            $this->shutdownRequested = true;
            
            // Stop the loop immediately
            \Dacheng\Yii2\Swoole\Queue\CoroutineLoop::stop();
        });
    }

    /**
     * Performs graceful shutdown sequence
     */
    protected function performGracefulShutdown(): void
    {
        $this->stdout("Flushing logs...\n");
        
        // Stop log workers first
        if (\Yii::$app->has('log')) {
            try {
                foreach (\Yii::$app->log->targets as $target) {
                    if (method_exists($target, 'getWorker')) {
                        $worker = $target->getWorker();
                        if ($worker instanceof LogWorker) {
                            $worker->stop();
                            
                            // Brief pause to let worker coroutine exit
                            if (extension_loaded('swoole')) {
                                Coroutine::sleep(0.05);
                            } else {
                                usleep(50000);
                            }
                        }
                    }
                }
                
                // Export remaining messages synchronously
                foreach (\Yii::$app->log->targets as $target) {
                    if (method_exists($target, 'export')) {
                        $messages = \Yii::$app->log->getLogger()->messages;
                        if (!empty($messages)) {
                            $target->collect($messages, true);
                            $target->export();
                        }
                    }
                }
                
                $this->stdout("Logs flushed\n");
            } catch (\Throwable $e) {
                $this->stderr("Error flushing logs: {$e->getMessage()}\n");
            }
        }

        $this->stdout("Closing connection pools...\n");
        
        // Close connection pools
        try {
            CoroutineDbConnection::shutdownAllPools();
        } catch (\Throwable $e) {
            $this->stderr("Error closing DB pools: {$e->getMessage()}\n");
        }
        
        try {
            CoroutineRedisConnection::shutdownAllPools();
        } catch (\Throwable $e) {
            $this->stderr("Error closing Redis pools: {$e->getMessage()}\n");
        }

        // Unregister signal handlers
        $this->unregisterSignalHandlers();

        $this->stdout("Graceful shutdown complete\n");
    }

    /**
     * Unregisters signal handlers
     */
    protected function unregisterSignalHandlers(): void
    {
        if (!extension_loaded('pcntl')) {
            return;
        }

        Process::signal(SIGTERM, null);
        Process::signal(SIGINT, null);
    }
}
