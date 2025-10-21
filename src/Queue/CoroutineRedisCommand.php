<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Queue;

use Swoole\Coroutine;
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
        $this->printCoroutineInfo();
        $result = $this->queue->run(false, 0);
        $this->stdout("All jobs processed.\n");
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

        $this->stdout("========================================\n");
        $this->stdout("Queue Worker Configuration\n");
        $this->stdout("========================================\n");
        $this->printCoroutineInfo();
        $this->stdout("Concurrency level: {$this->queue->concurrency}\n");
        $this->stdout("Execute inline: " . ($this->queue->executeInline ? 'YES' : 'NO') . "\n");
        $this->stdout("Timeout: {$timeout}s\n");
        $this->stdout("========================================\n\n");
        $this->stdout("Listening for jobs...\n\n");

        return $this->queue->run(true, $timeout);
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
}
