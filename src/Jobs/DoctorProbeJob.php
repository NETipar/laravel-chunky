<?php

declare(strict_types=1);

namespace NETipar\Chunky\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Dispatched by chunky:doctor to prove a queue worker is alive: the doctor
 * polls the cache key this job writes when a worker picks it up.
 */
final class DoctorProbeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public readonly string $cacheKey,
    ) {}

    public function handle(CacheFactory $cache): void
    {
        $cache->store()->put($this->cacheKey, true, 300);
    }
}
