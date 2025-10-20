<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Queue;

use Swoole\Coroutine;
use Swoole\Process;
use yii\console\Exception;
use yii\queue\cli\Command as CliCommand;
use yii\queue\cli\InfoAction;

/**
 * Manages application coroutine redis-queue with Swoole support.
 * 
 * This command class provides queue management with Swoole coroutine support.
 * The listen action runs in a Swoole process with coroutines enabled.
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
        $this->stdout("Processing all waiting jobs...\n");
        $result = $this->runInCoroutine(false, 0);
        $this->stdout("All jobs processed.\n");
        return $result;
    }

    /**
     * Listens redis-queue and runs new jobs with Swoole coroutine support.
     * 
     * This action runs in a Swoole coroutine context, enabling efficient
     * concurrent job processing with connection pooling.
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

        $this->stdout("========================================\n");
        $this->stdout("Queue Worker Configuration\n");
        $this->stdout("========================================\n");
        $this->stdout("Swoole coroutine support: ENABLED\n");
        $this->stdout("Concurrency level: {$this->queue->concurrency}\n");
        $this->stdout("Execute inline: " . ($this->queue->executeInline ? 'YES' : 'NO') . "\n");
        $this->stdout("Timeout: {$timeout}s\n");
        $this->stdout("========================================\n\n");
        $this->stdout("Listening for jobs...\n\n");

        return $this->runInCoroutine(true, $timeout);
    }
    
    /**
     * Runs the queue worker inside a coroutine context.
     * 
     * This ensures all Swoole-hooked functions work correctly.
     *
     * @param bool $repeat whether to continue listening when queue is empty.
     * @param int $timeout number of seconds to wait for next message.
     * @return null|int exit code.
     */
    protected function runInCoroutine($repeat, $timeout = 0)
    {
        if (!extension_loaded('swoole')) {
            // Fallback to non-coroutine mode if Swoole is not available
            return $this->queue->run($repeat, $timeout);
        }
        
        // Ensure coroutine hooks are enabled (enableCoroutine only takes 1 parameter: flags)
        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        
        $exitCode = null;
        
        // Run the queue worker in a coroutine
        \Swoole\Coroutine\run(function () use ($repeat, $timeout, &$exitCode) {
            $this->stdout("Coroutine started (ID: " . Coroutine::getCid() . ")\n");
            $exitCode = $this->queue->run($repeat, $timeout);
        });
        
        return $exitCode;
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
}
