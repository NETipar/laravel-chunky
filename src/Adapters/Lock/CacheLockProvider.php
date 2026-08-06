<?php

declare(strict_types=1);

namespace NETipar\Chunky\Adapters\Lock;

use Closure;
use Illuminate\Contracts\Cache\LockProvider as CacheLockContract;
use NETipar\Chunky\Ports\LockProvider;

final class CacheLockProvider implements LockProvider
{
    public function __construct(
        private readonly CacheLockContract $store,
        private readonly int $defaultTimeout = 10,
    ) {}

    public function withLock(string $key, Closure $callback, ?int $timeoutSeconds = null): mixed
    {
        $timeout = $timeoutSeconds ?? $this->defaultTimeout;

        return $this->store->lock('chunky:lock:'.$key, $timeout)->block($timeout, $callback);
    }
}
