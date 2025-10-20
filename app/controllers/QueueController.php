<?php

namespace app\controllers;

use app\jobs\ExampleJob;
use Yii;
use yii\web\Controller;
use yii\web\Response;

/**
 * Queue Controller for testing the Coroutine Redis Queue.
 * 
 * This controller provides endpoints to push jobs to the queue and check queue status.
 * 
 * WARNING: This controller is intended for development and testing.
 * In production, you should:
 * 1. Add proper authentication and authorization
 * 2. Add rate limiting for push actions
 * 3. Consider restricting access to specific IP addresses or networks
 */
class QueueController extends Controller
{
    /**
     * @inheritdoc
     */
    public $enableCsrfValidation = false;

    /**
     * Push a test job to the queue.
     * 
     * @return array
     */
    public function actionPush()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        
        $message = Yii::$app->request->get('message', 'Test job at ' . date('Y-m-d H:i:s'));
        $delay = (int)Yii::$app->request->get('delay', 0);
        
        $job = new ExampleJob([
            'message' => $message,
            'delay' => 0,
        ]);
        
        if ($delay > 0) {
            $jobId = Yii::$app->queue->delay($delay)->push($job);
            return [
                'success' => true,
                'jobId' => $jobId,
                'message' => "Job #{$jobId} pushed with {$delay}s delay",
                'data' => [
                    'message' => $message,
                    'delay' => $delay,
                ],
            ];
        }
        
        $jobId = Yii::$app->queue->push($job);
        
        return [
            'success' => true,
            'jobId' => $jobId,
            'message' => "Job #{$jobId} pushed successfully",
            'data' => [
                'message' => $message,
            ],
        ];
    }

    /**
     * Get queue statistics.
     * 
     * @return array
     */
    public function actionStats()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        
        $stats = Yii::$app->queue->getStatisticsProvider();
        
        return [
            'success' => true,
            'stats' => [
                'waiting' => $stats->getWaitingCount(),
                'delayed' => $stats->getDelayedCount(),
                'reserved' => $stats->getReservedCount(),
                'done' => $stats->getDoneCount(),
            ],
        ];
    }

    /**
     * Check job status by ID.
     * 
     * @param int $id
     * @return array
     */
    public function actionStatus($id)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        
        $status = Yii::$app->queue->status($id);
        
        $statusMap = [
            \yii\queue\Queue::STATUS_WAITING => 'waiting',
            \yii\queue\Queue::STATUS_RESERVED => 'reserved',
            \yii\queue\Queue::STATUS_DONE => 'done',
        ];
        
        return [
            'success' => true,
            'jobId' => $id,
            'status' => $statusMap[$status] ?? 'unknown',
            'statusCode' => $status,
        ];
    }

    /**
     * Push multiple test jobs to the queue.
     * 
     * @return array
     */
    public function actionPushBatch()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        
        $count = (int)Yii::$app->request->get('count', 10);
        $count = min($count, 100); // Limit to 100 jobs
        
        $jobIds = [];
        for ($i = 1; $i <= $count; $i++) {
            $job = new ExampleJob([
                'message' => "Batch job #{$i} at " . date('Y-m-d H:i:s'),
                'delay' => 1,
            ]);
            
            $jobIds[] = Yii::$app->queue->push($job);
        }
        
        return [
            'success' => true,
            'count' => $count,
            'jobIds' => $jobIds,
            'message' => "{$count} jobs pushed successfully",
        ];
    }
}
