<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Log;

use Swoole\Coroutine\Channel;
use Yii;
use yii\log\Target;

/**
 * CoroutineFileTarget writes log messages to a file asynchronously using Swoole channels.
 * 
 * Unlike the standard FileTarget which blocks on file I/O operations, this target
 * pushes log messages to a channel buffer and a background coroutine worker handles
 * the actual file writing asynchronously.
 * 
 * Features:
 * - Non-blocking log writes via Swoole channels
 * - Configurable channel buffer size
 * - Automatic file rotation support
 * - Graceful shutdown handling
 * - Thread-safe in coroutine context
 * 
 * Example configuration:
 * ```php
 * 'log' => [
 *     'targets' => [
 *         [
 *             'class' => \Dacheng\Yii2\Swoole\Log\CoroutineFileTarget::class,
 *             'levels' => ['error', 'warning', 'info'],
 *             'logFile' => '@runtime/logs/app.log',
 *             'channelSize' => 10000,
 *             'maxFileSize' => 10240, // 10MB
 *         ],
 *     ],
 * ],
 * ```
 */
class CoroutineFileTarget extends Target
{
    /**
     * @var string|null log file path or path alias
     */
    public $logFile;

    /**
     * @var bool whether log files should be rotated when they reach maxFileSize
     */
    public $enableRotation = true;

    /**
     * @var int maximum log file size in kilobytes (default: 10MB)
     */
    public $maxFileSize = 10240;

    /**
     * @var int number of log files used for rotation
     */
    public $maxLogFiles = 5;

    /**
     * @var int|null file permission for newly created log files
     */
    public $fileMode;

    /**
     * @var int directory permission for newly created directories
     */
    public $dirMode = 0775;

    /**
     * @var int channel buffer size (number of log entries)
     */
    public $channelSize = 10000;

    /**
     * @var float timeout in seconds for pushing to channel (default: 0.1s)
     * Increase this if you see "Channel push failed" errors during high load
     */
    public $pushTimeout = 0.1;

    /**
     * @var int maximum messages per batch write (default: 1000)
     * Higher values = faster throughput, lower values = more responsive
     */
    public $batchSize = 1000;

    /**
     * @var Channel|null Swoole channel for async message passing
     */
    private ?Channel $channel = null;

    /**
     * @var LogWorker|null background worker coroutine
     */
    private ?LogWorker $worker = null;

    /**
     * @var bool whether the target has been initialized
     */
    private bool $initialized = false;

    /**
     * Get the LogWorker instance for graceful shutdown
     * 
     * @return LogWorker|null
     */
    public function getWorker(): ?LogWorker
    {
        return $this->worker;
    }

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();

        if ($this->logFile === null) {
            $this->logFile = Yii::$app->getRuntimePath() . '/logs/app.log';
        } else {
            $this->logFile = Yii::getAlias($this->logFile);
        }

        // Validate configuration
        $this->maxLogFiles = max(1, $this->maxLogFiles);
        $this->maxFileSize = max(1, $this->maxFileSize);
        $this->channelSize = max(1000, $this->channelSize);
        $this->pushTimeout = max(0.001, $this->pushTimeout);
        $this->batchSize = max(100, $this->batchSize);

        // Initialize channel and worker in a coroutine context
        $this->ensureInitialized();
    }

    /**
     * Ensures the channel and worker are initialized
     */
    private function ensureInitialized(): void
    {
        if ($this->initialized) {
            return;
        }

        // Only initialize if we're in a Swoole coroutine context
        if (\Swoole\Coroutine::getCid() > 0) {
            $this->channel = new Channel($this->channelSize);
            
            $this->worker = new LogWorker(
                $this->channel,
                $this->logFile,
                $this->enableRotation,
                $this->maxFileSize,
                $this->maxLogFiles,
                $this->fileMode,
                $this->dirMode,
                $this->batchSize
            );

            $this->worker->start();
            $this->initialized = true;
        }
    }

    /**
     * @inheritdoc
     */
    public function export()
    {
        if (empty($this->messages)) {
            return;
        }

        // Check if we're in a coroutine context first
        $inCoroutine = extension_loaded('swoole') && \Swoole\Coroutine::getCid() > 0;
        
        if (!$inCoroutine) {
            // Not in coroutine, use sync export
            $this->exportSync();
            return;
        }

        // Ensure we're initialized before exporting
        $this->ensureInitialized();

        if (!$this->initialized || $this->channel === null) {
            // Fallback to synchronous writing if not initialized
            $this->exportSync();
            return;
        }

        // Format all messages
        $formattedMessages = array_map([$this, 'formatMessage'], $this->messages);

        if (empty($formattedMessages)) {
            return;
        }

        // Push to channel with configurable timeout
        $messagePacket = [
            'messages' => $formattedMessages,
            'timestamp' => microtime(true),
        ];

        try {
            $pushed = $this->channel->push($messagePacket, $this->pushTimeout);
            
            if (!$pushed) {
                // Channel is full or timed out, fall back to sync write
                error_log('[CoroutineFileTarget] Channel push failed, falling back to sync write');
                $this->exportSync();
            }
        } catch (\Throwable $e) {
            // Channel closed or other error, fall back to sync write
            error_log('[CoroutineFileTarget] Channel error: ' . $e->getMessage());
            $this->exportSync();
        }
    }

    /**
     * Synchronous export fallback (when not in coroutine context or channel is full)
     */
    private function exportSync(): void
    {
        if (empty($this->messages)) {
            return;
        }

        $formattedMessages = array_map([$this, 'formatMessage'], $this->messages);
        $text = implode("\n", $formattedMessages) . "\n";

        $logPath = dirname($this->logFile);
        if (!is_dir($logPath)) {
            @mkdir($logPath, $this->dirMode, true);
        }

        if (($fp = @fopen($this->logFile, 'a')) === false) {
            error_log("Unable to append to log file: {$this->logFile}");
            return;
        }

        @flock($fp, LOCK_EX);
        
        if ($this->enableRotation && @filesize($this->logFile) > $this->maxFileSize * 1024) {
            $this->rotateFilesSync();
        }

        @fwrite($fp, $text);
        @fflush($fp);
        @flock($fp, LOCK_UN);
        @fclose($fp);

        if ($this->fileMode !== null) {
            @chmod($this->logFile, $this->fileMode);
        }
    }

    /**
     * Synchronous file rotation
     */
    private function rotateFilesSync(): void
    {
        $file = $this->logFile;
        for ($i = $this->maxLogFiles; $i >= 0; --$i) {
            $rotateFile = $file . ($i === 0 ? '' : '.' . $i);
            if (is_file($rotateFile)) {
                if ($i === $this->maxLogFiles) {
                    @unlink($rotateFile);
                    continue;
                }
                $newFile = $this->logFile . '.' . ($i + 1);
                @copy($rotateFile, $newFile);
                if ($this->fileMode !== null) {
                    @chmod($newFile, $this->fileMode);
                }
                if ($i === 0) {
                    if ($fp = @fopen($rotateFile, 'a')) {
                        @ftruncate($fp, 0);
                        @fclose($fp);
                    }
                }
            }
        }
    }

    /**
     * Shuts down the async worker and flushes remaining messages
     */
    public function shutdown(): void
    {
        if (!$this->initialized) {
            return;
        }

        // Stop worker first (this will flush remaining messages)
        if ($this->worker !== null) {
            $this->worker->stop();
            $this->worker = null;
        }

        // Close channel after worker has stopped
        if ($this->channel !== null) {
            $this->channel->close();
            $this->channel = null;
        }

        $this->initialized = false;
    }

    /**
     * Gets channel statistics
     * 
     * @return array|null Channel stats or null if not initialized
     */
    public function getChannelStats(): ?array
    {
        if (!$this->initialized || $this->channel === null) {
            return null;
        }

        $stats = $this->channel->stats();
        
        return [
            'capacity' => $this->channelSize,
            'queued' => (int)($stats['queue_num'] ?? 0),
            'usage_percent' => round(((int)($stats['queue_num'] ?? 0) / $this->channelSize) * 100, 2),
            'consumer_waiting' => (int)($stats['consumer_num'] ?? 0),
            'producer_waiting' => (int)($stats['producer_num'] ?? 0),
        ];
    }

    /**
     * @inheritdoc
     */
    public function __destruct()
    {
        $this->shutdown();
    }
}
