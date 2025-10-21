<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Server;

use Swoole\Coroutine;
use Swoole\Process;

/**
 * SignalHandler manages graceful shutdown on SIGTERM/SIGINT signals.
 * 
 * This handler:
 * - Registers signal handlers for SIGTERM and SIGINT
 * - Coordinates graceful shutdown of server components
 * - Ensures connection pools are closed properly
 * - Flushes logs before exit
 * - Allows in-flight requests to complete
 */
class SignalHandler
{
    private const SHUTDOWN_TIMEOUT = 30.0; // Maximum time to wait for graceful shutdown
    private const CHECK_INTERVAL = 0.1;    // Interval to check shutdown status
    
    private bool $shutdownRequested = false;
    private bool $isShuttingDown = false;
    private array $shutdownCallbacks = [];
    private ?float $shutdownStartTime = null;
    
    /**
     * Registers signal handlers for SIGTERM and SIGINT
     */
    public function register(): void
    {
        if (!extension_loaded('pcntl')) {
            error_log('[SignalHandler] PCNTL extension not loaded, signal handling disabled');
            return;
        }
        
        // Use Swoole's Process::signal for coroutine-safe signal handling
        Process::signal(SIGTERM, function (int $signo) {
            $this->handleShutdownSignal($signo);
        });
        
        Process::signal(SIGINT, function (int $signo) {
            $this->handleShutdownSignal($signo);
        });
        
        error_log('[SignalHandler] Registered handlers for SIGTERM and SIGINT');
    }
    
    /**
     * Unregisters signal handlers
     */
    public function unregister(): void
    {
        if (!extension_loaded('pcntl')) {
            return;
        }
        
        Process::signal(SIGTERM, null);
        Process::signal(SIGINT, null);
    }
    
    /**
     * Registers a callback to be executed during shutdown
     * 
     * @param string $name Unique name for the callback
     * @param callable $callback Callback to execute (should return void)
     * @param int $priority Priority (lower numbers execute first, default 100)
     */
    public function onShutdown(string $name, callable $callback, int $priority = 100): void
    {
        $this->shutdownCallbacks[$name] = [
            'callback' => $callback,
            'priority' => $priority,
        ];
    }
    
    /**
     * Checks if shutdown has been requested
     */
    public function isShutdownRequested(): bool
    {
        return $this->shutdownRequested;
    }
    
    /**
     * Checks if shutdown is in progress
     */
    public function isShuttingDown(): bool
    {
        return $this->isShuttingDown;
    }
    
    /**
     * Handles shutdown signals (SIGTERM/SIGINT)
     */
    private function handleShutdownSignal(int $signo): void
    {
        $signalName = $signo === SIGTERM ? 'SIGTERM' : 'SIGINT';
        
        if ($this->shutdownRequested) {
            error_log("[SignalHandler] Received {$signalName} again, forcing immediate exit");
            exit(1);
        }
        
        $this->shutdownRequested = true;
        error_log("[SignalHandler] Received {$signalName}, initiating graceful shutdown...");
        
        // Perform graceful shutdown in a coroutine
        Coroutine::create(function () {
            $this->performGracefulShutdown();
        });
    }
    
    /**
     * Performs graceful shutdown sequence
     */
    private function performGracefulShutdown(): void
    {
        if ($this->isShuttingDown) {
            return;
        }
        
        $this->isShuttingDown = true;
        $this->shutdownStartTime = microtime(true);
        
        error_log('[SignalHandler] Starting graceful shutdown sequence');
        
        // Sort callbacks by priority
        $callbacks = $this->shutdownCallbacks;
        uasort($callbacks, function ($a, $b) {
            return $a['priority'] <=> $b['priority'];
        });
        
        // Execute shutdown callbacks in priority order
        foreach ($callbacks as $name => $config) {
            $elapsed = microtime(true) - $this->shutdownStartTime;
            
            if ($elapsed >= self::SHUTDOWN_TIMEOUT) {
                error_log("[SignalHandler] Shutdown timeout reached, skipping remaining callbacks");
                break;
            }
            
            try {
                error_log("[SignalHandler] Executing shutdown callback: {$name}");
                $config['callback']();
            } catch (\Throwable $e) {
                error_log("[SignalHandler] Error in shutdown callback '{$name}': {$e->getMessage()}");
            }
        }
        
        $totalTime = microtime(true) - $this->shutdownStartTime;
        error_log(sprintf('[SignalHandler] Graceful shutdown completed in %.3f seconds', $totalTime));
    }
    
    /**
     * Waits for in-flight requests to complete
     * 
     * @param callable $checkCallback Callback that returns true if requests are still in-flight
     * @param float $maxWaitTime Maximum time to wait in seconds
     */
    public function waitForInflightRequests(callable $checkCallback, float $maxWaitTime = 5.0): void
    {
        $startTime = microtime(true);
        
        while (microtime(true) - $startTime < $maxWaitTime) {
            if (!$checkCallback()) {
                error_log('[SignalHandler] All in-flight requests completed');
                return;
            }
            
            Coroutine::sleep(self::CHECK_INTERVAL);
        }
        
        error_log('[SignalHandler] Timeout waiting for in-flight requests, proceeding with shutdown');
    }
}
