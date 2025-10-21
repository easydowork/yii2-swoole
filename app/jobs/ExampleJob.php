<?php

namespace dacheng\app\jobs;

use Swoole\Coroutine;
use yii\base\BaseObject;
use yii\queue\JobInterface;

/**
 * Example Job for testing the Coroutine Redis Queue.
 *
 * This job demonstrates how to create and execute jobs with the queue system.
 */
class ExampleJob extends BaseObject implements JobInterface
{
    /**
     * @var string Message to process
     */
    public $message;

    /**
     * @var int Simulated processing time in seconds
     */
    public $delay = 0;

    /**
     * Execute the job.
     *
     * @param \yii\queue\Queue $queue
     */
    public function execute($queue)
    {
        $startTime = microtime(true);
        $cid = Coroutine::getCid();
        
        \Yii::info("Job started: {$this->message} (Coroutine ID: {$cid})", __METHOD__);
        
        if ($this->delay > 0) {
            Coroutine::sleep($this->delay);
        }
        
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        \Yii::info("Job completed: {$this->message} (Duration: {$duration}ms)", __METHOD__);
    }
}
