<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Log;

use Swoole\Timer;

/**
 * LogWorker handles asynchronous file writing using a direct buffer approach.
 * 
 * Messages are pushed directly to an internal buffer, and a timer periodically
 * flushes the buffer to disk. This design avoids Channel operations and deadlock risks.
 */
class LogWorker
{
    private const BATCH_WRITE_INTERVAL = 100;
    private const MAX_BUFFER_SIZE = 100000;

    private string $logFile;
    private bool $enableRotation;
    private int $maxFileSize;
    private int $maxLogFiles;
    private ?int $fileMode;
    private int $dirMode;
    private bool $running = false;
    private ?int $writeTimer = null;
    private array $messageBuffer = [];
    private int $droppedMessages = 0;

    public function __construct(
        string $logFile,
        bool $enableRotation,
        int $maxFileSize,
        int $maxLogFiles,
        ?int $fileMode,
        int $dirMode
    ) {
        $this->logFile = $logFile;
        $this->enableRotation = $enableRotation;
        $this->maxFileSize = $maxFileSize;
        $this->maxLogFiles = $maxLogFiles;
        $this->fileMode = $fileMode;
        $this->dirMode = $dirMode;
    }

    public function start(): void
    {
        if ($this->running) {
            return;
        }

        $this->running = true;

        $logPath = dirname($this->logFile);
        if (!is_dir($logPath)) {
            @mkdir($logPath, $this->dirMode, true);
        }

        $this->writeTimer = Timer::tick(self::BATCH_WRITE_INTERVAL, function () {
            if (!$this->running) {
                return;
            }
            $this->writeBufferedMessages();
        });
    }

    public function stop(): void
    {
        if (!$this->running) {
            return;
        }

        $this->running = false;

        if ($this->writeTimer !== null) {
            Timer::clear($this->writeTimer);
            $this->writeTimer = null;
        }

        $this->writeBufferedMessages();

        if (!empty($this->messageBuffer)) {
            // Use error_log here to avoid recursion in logging system
            error_log('[LogWorker] Warning: ' . count($this->messageBuffer) . ' messages remain after stop');
        }
        
        if ($this->droppedMessages > 0) {
            // Use error_log here to avoid recursion in logging system
            error_log('[LogWorker] Warning: ' . $this->droppedMessages . ' messages were dropped (buffer full)');
        }
    }

    public function pushMessages(array $messages): bool
    {
        if (!$this->running) {
            return false;
        }

        if (count($this->messageBuffer) >= self::MAX_BUFFER_SIZE) {
            $this->droppedMessages += count($messages);
            return false;
        }

        foreach ($messages as $message) {
            $this->messageBuffer[] = $message;
        }

        return true;
    }

    private function writeBufferedMessages(): void
    {
        if (empty($this->messageBuffer)) {
            return;
        }

        $messages = $this->messageBuffer;
        $this->messageBuffer = [];

        $this->writeMessages($messages);
    }

    private function writeMessages(array $messages): void
    {
        if (empty($messages)) {
            return;
        }

        $text = implode("\n", $messages) . "\n";

        if (trim($text) === '') {
            return;
        }

        $success = @file_put_contents($this->logFile, $text, FILE_APPEND | LOCK_EX);
        
        if ($success === false) {
            // Use error_log here to avoid recursion in logging system
            error_log("LogWorker: Unable to write to log file: {$this->logFile}");
            return;
        }
        
        if ($this->fileMode !== null) {
            @chmod($this->logFile, $this->fileMode);
        }
        
        if ($this->enableRotation) {
            clearstatcache();
            $fileSize = @filesize($this->logFile);
            if ($fileSize !== false && $fileSize > $this->maxFileSize * 1024) {
                $this->rotateFiles();
            }
        }
    }

    private function rotateFiles(): void
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
                    $this->clearLogFile($rotateFile);
                }
            }
        }
    }

    private function clearLogFile(string $file): void
    {
        $fp = @fopen($file, 'a');
        if ($fp !== false) {
            @ftruncate($fp, 0);
            @fclose($fp);
        }
    }
}
