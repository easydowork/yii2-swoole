<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Log;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

/**
 * LogWorker handles asynchronous file writing in a separate coroutine.
 * 
 * This worker runs in its own coroutine and continuously reads log messages
 * from a channel, then writes them to disk. This ensures that log operations
 * do not block the main application flow.
 */
class LogWorker
{
    // Timeout constants for channel operations
    private const WAIT_TIMEOUT = 0.1;          // Timeout for waiting on new messages
    private const BATCH_TIMEOUT = 0.001;       // Timeout for collecting batch messages
    private const FLUSH_TIMEOUT = 0.001;       // Timeout for flushing on shutdown
    private const STOP_SIGNAL_TIMEOUT = 0.1;   // Timeout for sending stop signal
    private const STOP_WAIT_TIMEOUT = 0.2;     // Timeout for waiting worker to stop
    
    // Maximum messages to collect per flush batch
    private const FLUSH_MAX_MESSAGES = 5000;

    private Channel $channel;
    private string $logFile;
    private bool $enableRotation;
    private int $maxFileSize;
    private int $maxLogFiles;
    private ?int $fileMode;
    private int $dirMode;
    private int $batchSize;
    private bool $running = false;
    private ?int $coroutineId = null;

    /**
     * @param Channel $channel Channel to receive log messages from
     * @param string $logFile Path to the log file
     * @param bool $enableRotation Whether to enable log rotation
     * @param int $maxFileSize Maximum file size in KB before rotation
     * @param int $maxLogFiles Number of rotated log files to keep
     * @param int|null $fileMode File permission for log files
     * @param int $dirMode Directory permission for log directories
     * @param int $batchSize Maximum packets to collect per batch write
     */
    public function __construct(
        Channel $channel,
        string $logFile,
        bool $enableRotation,
        int $maxFileSize,
        int $maxLogFiles,
        ?int $fileMode,
        int $dirMode,
        int $batchSize = 1000
    ) {
        $this->channel = $channel;
        $this->logFile = $logFile;
        $this->enableRotation = $enableRotation;
        $this->maxFileSize = $maxFileSize;
        $this->maxLogFiles = $maxLogFiles;
        $this->fileMode = $fileMode;
        $this->dirMode = $dirMode;
        $this->batchSize = max(100, $batchSize);
    }

    /**
     * Starts the worker coroutine
     */
    public function start(): void
    {
        if ($this->running) {
            return;
        }

        $this->running = true;

        $this->coroutineId = Coroutine::create(function () {
            $this->run();
        });
    }

    /**
     * Stops the worker coroutine gracefully
     */
    public function stop(): void
    {
        if (!$this->running) {
            return;
        }

        $this->running = false;

        // Close channel immediately to wake up blocked pop()
        try {
            if ($this->channel !== null) {
                $stats = @$this->channel->stats();
                if ($stats !== false) {
                    $this->channel->close();
                }
            }
        } catch (\Throwable $e) {
            // Silently handle channel close errors
        }

        $this->coroutineId = null;
    }

    /**
     * Main worker loop
     */
    private function run(): void
    {
        // Ensure log directory exists
        $logPath = dirname($this->logFile);
        if (!is_dir($logPath)) {
            @mkdir($logPath, $this->dirMode, true);
        }

        while ($this->running) {
            // Wait for first packet
            $packet = $this->channel->pop(self::WAIT_TIMEOUT);

            if ($packet === false) {
                // Timeout or channel closed - check if channel is closed
                $stats = @$this->channel->stats();
                if ($stats === false) {
                    break;  // Channel closed, exit
                }
                continue;  // Just timeout, continue loop
            }

            // Check for stop signal
            if ($this->isStopSignal($packet)) {
                break;
            }

            // Collect messages in batch for better performance
            $batchMessages = $this->collectBatch($packet);

            // Write collected batch to file
            if (!empty($batchMessages)) {
                $this->writeMessages($batchMessages);
            }
        }

        // Flush any remaining messages before stopping
        $this->flushRemainingMessages();
    }

    /**
     * Writes messages to the log file
     * 
     * @param array $messages Array of formatted log messages
     */
    private function writeMessages(array $messages): void
    {
        if (empty($messages)) {
            return;
        }

        $text = implode("\n", $messages) . "\n";

        if (trim($text) === '') {
            return;
        }

        // Open file for appending
        $fp = @fopen($this->logFile, 'a');
        if ($fp === false) {
            error_log("LogWorker: Unable to open log file: {$this->logFile}");
            return;
        }

        // Lock file
        @flock($fp, LOCK_EX);

        // Check if rotation is needed
        if ($this->enableRotation) {
            clearstatcache();
            $fileSize = @filesize($this->logFile);
            if ($fileSize !== false && $fileSize > $this->maxFileSize * 1024) {
                // Release lock before rotation
                @flock($fp, LOCK_UN);
                @fclose($fp);
                
                $this->rotateFiles();
                
                // Reopen file after rotation
                $fp = @fopen($this->logFile, 'a');
                if ($fp === false) {
                    error_log("LogWorker: Unable to reopen log file after rotation: {$this->logFile}");
                    return;
                }
                @flock($fp, LOCK_EX);
            }
        }

        // Write to file
        $writeResult = @fwrite($fp, $text);
        if ($writeResult === false) {
            error_log("LogWorker: Failed to write to log file: {$this->logFile}");
        }

        // Flush and close
        @fflush($fp);
        @flock($fp, LOCK_UN);
        @fclose($fp);

        // Set file permissions if specified
        if ($this->fileMode !== null) {
            @chmod($this->logFile, $this->fileMode);
        }
    }

    /**
     * Rotates log files
     */
    private function rotateFiles(): void
    {
        $file = $this->logFile;

        for ($i = $this->maxLogFiles; $i >= 0; --$i) {
            $rotateFile = $file . ($i === 0 ? '' : '.' . $i);
            
            if (is_file($rotateFile)) {
                if ($i === $this->maxLogFiles) {
                    // Delete the oldest file
                    @unlink($rotateFile);
                    continue;
                }

                // Rename to next number
                $newFile = $this->logFile . '.' . ($i + 1);
                @copy($rotateFile, $newFile);
                
                if ($this->fileMode !== null) {
                    @chmod($newFile, $this->fileMode);
                }

                if ($i === 0) {
                    // Truncate the current log file
                    $this->clearLogFile($rotateFile);
                }
            }
        }
    }

    /**
     * Clears the log file without closing other process handles
     * 
     * @param string $file File to clear
     */
    private function clearLogFile(string $file): void
    {
        $fp = @fopen($file, 'a');
        if ($fp !== false) {
            @ftruncate($fp, 0);
            @fclose($fp);
        }
    }

    /**
     * Collects multiple packets into a batch for writing
     * 
     * @param mixed $firstPacket First packet to include in batch
     * @return array Array of log messages
     */
    private function collectBatch($firstPacket): array
    {
        $batchMessages = [];
        $packetsProcessed = 0;

        // Process first packet
        if ($this->isMessagePacket($firstPacket)) {
            foreach ($firstPacket['messages'] as $msg) {
                $batchMessages[] = $msg;
            }
            $packetsProcessed++;
        }

        // Try to collect more packets without blocking
        while ($packetsProcessed < $this->batchSize && $this->running) {
            $packet = $this->channel->pop(self::BATCH_TIMEOUT);

            if ($packet === false) {
                break; // No more messages available right now
            }

            if ($this->isStopSignal($packet)) {
                $this->running = false;
                break;
            }

            if ($this->isMessagePacket($packet)) {
                foreach ($packet['messages'] as $msg) {
                    $batchMessages[] = $msg;
                }
                $packetsProcessed++;
            }
        }

        return $batchMessages;
    }

    /**
     * Checks if packet is a stop signal
     * 
     * @param mixed $packet Packet to check
     * @return bool
     */
    private function isStopSignal($packet): bool
    {
        return is_array($packet) && isset($packet['__stop__']);
    }

    /**
     * Checks if packet contains log messages
     * 
     * @param mixed $packet Packet to check
     * @return bool
     */
    private function isMessagePacket($packet): bool
    {
        return is_array($packet) && isset($packet['messages']) && is_array($packet['messages']);
    }

    /**
     * Flushes any remaining messages in the channel before shutdown
     */
    private function flushRemainingMessages(): void
    {
        $totalFlushed = 0;

        // Keep draining until channel is empty
        while (true) {
            $batchMessages = [];

            // Collect messages with very short timeout
            while (count($batchMessages) < self::FLUSH_MAX_MESSAGES) {
                $packet = $this->channel->pop(self::FLUSH_TIMEOUT);
                
                if ($packet === false) {
                    break; // No more messages
                }

                if ($this->isMessagePacket($packet)) {
                    foreach ($packet['messages'] as $message) {
                        $batchMessages[] = $message;
                    }
                }
            }

            // No more messages to flush
            if (empty($batchMessages)) {
                break;
            }

            // Write batch to disk
            $this->writeMessages($batchMessages);
            $totalFlushed += count($batchMessages);
        }

        if ($totalFlushed > 0) {
            error_log("[LogWorker] Flushed {$totalFlushed} remaining messages on shutdown");
        }
    }
}
