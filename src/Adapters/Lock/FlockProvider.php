<?php

declare(strict_types=1);

namespace NETipar\Chunky\Adapters\Lock;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use NETipar\Chunky\Exceptions\ChunkyException;
use NETipar\Chunky\Ports\LockProvider;

final class FlockProvider implements LockProvider
{
    public function __construct(
        private readonly string $lockDirectory,
        private readonly int $defaultTimeout = 10,
    ) {}

    public function withLock(string $key, Closure $callback, ?int $timeoutSeconds = null): mixed
    {
        $timeout = $timeoutSeconds ?? $this->defaultTimeout;
        $this->ensureDirectory();

        $file = $this->lockDirectory.'/'.hash('sha256', $key).'.lock';
        $handle = fopen($file, 'c');

        if ($handle === false) {
            throw new ChunkyException("Cannot open lock file for '{$key}'.");
        }

        $deadline = microtime(true) + $timeout;

        while (! flock($handle, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                fclose($handle);

                throw new LockTimeoutException("Timed out acquiring lock '{$key}'.");
            }

            usleep(25_000);
        }

        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function ensureDirectory(): void
    {
        if (! is_dir($this->lockDirectory) && ! @mkdir($this->lockDirectory, 0755, true) && ! is_dir($this->lockDirectory)) {
            throw new ChunkyException("Cannot create lock directory '{$this->lockDirectory}'.");
        }
    }
}
