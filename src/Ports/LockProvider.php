<?php

declare(strict_types=1);

namespace NETipar\Chunky\Ports;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;

interface LockProvider
{
    /**
     * Run $callback while holding the named lock.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     *
     * @throws LockTimeoutException when the lock cannot be acquired in time.
     */
    public function withLock(string $key, Closure $callback, ?int $timeoutSeconds = null): mixed;
}
