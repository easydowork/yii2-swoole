<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Server;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Swoole\Http\Server as SwooleHttpServer;
use Swoole\Timer;
use Yii;
use yii\base\BaseObject;

class HotReloader extends BaseObject
{
    public bool $enabled = false;

    public array $watchPaths = [];

    public array $extensions = ['php'];

    public int $interval = 1000;

    private array $snapshot = [];

    private ?int $timerId = null;

    public function enable(bool $enable, array $paths = []): void
    {
        $this->enabled = $enable;

        if ($paths !== []) {
            $this->watchPaths = $paths;
        }

        if (!$enable) {
            $this->stop();
        }
    }

    public function start(SwooleHttpServer $server): void
    {
        if (!$this->enabled) {
            return;
        }

        $paths = $this->resolvePaths();
        if ($paths === []) {
            return;
        }

        $this->stop();

        $this->snapshot = $this->snapshotPaths($paths);
        $interval = $this->interval < 100 ? 100 : $this->interval;

        $this->timerId = Timer::tick($interval, function () use ($server, $paths): void {
            if ($this->hasChanges($paths)) {
                echo "[swoole] Detected changes, reloading...\n";
                $server->reload();
            }
        });
    }

    public function stop(): void
    {
        if ($this->timerId !== null) {
            Timer::clear($this->timerId);
            $this->timerId = null;
        }
    }

    private function resolvePaths(): array
    {
        $paths = $this->watchPaths === [] ? ['@app'] : $this->watchPaths;

        $resolved = [];
        foreach ($paths as $path) {
            $normalized = $this->normalizePath($path);
            if ($normalized !== null && !in_array($normalized, $resolved, true)) {
                $resolved[] = $normalized;
            }
        }

        return $resolved;
    }

    private function normalizePath(string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        if ($path[0] === '@') {
            $alias = Yii::getAlias($path, false);
            if ($alias === false) {
                return null;
            }
            $path = $alias;
        }

        $real = realpath($path);

        return $real === false ? null : $real;
    }

    private function snapshotPaths(array $paths): array
    {
        $files = [];
        $extensions = $this->extensions === [] ? [] : array_map('strtolower', $this->extensions);

        foreach ($paths as $path) {
            if (is_file($path)) {
                $this->appendSnapshot($files, $path, null, $extensions);
                continue;
            }

            if (!is_dir($path)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $fileInfo) {
                if ($fileInfo->isFile()) {
                    $this->appendSnapshot($files, $fileInfo->getPathname(), $fileInfo->getMTime(), $extensions);
                }
            }
        }

        ksort($files);

        return $files;
    }

    private function appendSnapshot(array &$files, string $file, ?int $mtime, array $extensions): void
    {
        if (!$this->shouldTrack($file, $extensions)) {
            return;
        }

        $files[$file] = $mtime ?? filemtime($file) ?: time();
    }

    private function shouldTrack(string $file, array $extensions): bool
    {
        $extension = pathinfo($file, PATHINFO_EXTENSION);
        if ($extension === '') {
            return false;
        }

        if ($extensions === []) {
            return true;
        }

        return in_array(strtolower($extension), $extensions, true);
    }

    private function hasChanges(array $paths): bool
    {
        $current = $this->snapshotPaths($paths);
        if ($this->snapshot === []) {
            $this->snapshot = $current;

            return false;
        }

        if ($current !== $this->snapshot) {
            $this->snapshot = $current;

            return true;
        }

        return false;
    }
}
