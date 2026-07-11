<?php

declare(strict_types=1);

namespace NETipar\Chunky\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use NETipar\Chunky\Config\ChunkyConfig;
use NETipar\Chunky\Services\AssemblyRunner;
use Throwable;

final class AssembleFileJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries;

    public int $timeout;

    public int $backoff;

    public function __construct(
        public readonly string $uploadId,
    ) {
        $config = app(ChunkyConfig::class);

        $this->onConnection($this->normalizeConnection($config->assemblyConnection));
        $this->onQueue($config->assemblyQueue);
        $this->tries = $config->assemblyTries;
        $this->timeout = $config->assemblyTimeout;
        $this->backoff = $config->assemblyBackoff;
    }

    public function handle(AssemblyRunner $runner): void
    {
        $runner->run($this->uploadId);
    }

    public function failed(?Throwable $exception): void
    {
        app(AssemblyRunner::class)->markFailed(
            $this->uploadId,
            $exception?->getMessage() ?? 'Assembly failed.',
        );
    }

    private function normalizeConnection(?string $connection): ?string
    {
        return ($connection === null || $connection === '') ? null : $connection;
    }
}
