<?php

declare(strict_types=1);

namespace NETipar\Chunky\Console;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Console\Command;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use NETipar\Chunky\Adapters\Filesystem\JsonFileStore;
use NETipar\Chunky\Config\ChunkyConfig;
use NETipar\Chunky\Jobs\DoctorProbeJob;
use NETipar\Chunky\Ports\LockProvider;
use NETipar\Chunky\Support\Coerce;
use Throwable;

final class DoctorCommand extends Command
{
    protected $signature = 'chunky:doctor {--wait=5 : Seconds to wait for the queue probe}';

    protected $description = 'Diagnose the Chunky setup (disks, queue worker, broadcasting, locking, tracker).';

    private bool $failed = false;

    public function handle(
        ChunkyConfig $config,
        FilesystemFactory $filesystem,
        CacheFactory $cache,
        BroadcastFactory $broadcast,
        Repository $appConfig,
        LockProvider $locks,
    ): int {
        $this->line('Chunky configuration');
        $this->line("  tracker:        {$config->tracker}");
        $this->line("  final disk:     {$config->disk}");
        $this->line("  chunk disk:     {$config->chunkDisk}");
        $this->line("  assembly mode:  {$config->assemblyMode}");
        $this->line('  locking driver: '.$config->lockingDriver);
        $this->newLine();

        $this->checkDiskWritable($filesystem, $config->chunkDisk, 'chunk disk');
        $this->checkDiskWritable($filesystem, $config->disk, 'final disk');
        $this->checkQueueWorker($config, $cache, $appConfig);
        $this->checkBroadcasting($config, $broadcast, $appConfig);
        $this->checkLocking($locks);
        $this->checkTracker($config, $filesystem);

        return $this->failed ? self::FAILURE : self::SUCCESS;
    }

    private function checkDiskWritable(FilesystemFactory $filesystem, string $disk, string $label): void
    {
        try {
            $probe = 'chunky/.doctor-probe';
            $filesystem->disk($disk)->put($probe, 'ok');
            $filesystem->disk($disk)->delete($probe);
            $this->info("{$label} '{$disk}' is writable.");
        } catch (Throwable $e) {
            $this->reportError("{$label} '{$disk}' is NOT writable: {$e->getMessage()}");
        }
    }

    private function checkQueueWorker(ChunkyConfig $config, CacheFactory $cache, Repository $appConfig): void
    {
        if ($config->assemblyMode === 'sync') {
            $this->info('assembly.mode=sync — no queue worker required.');

            return;
        }

        $wait = max(0, Coerce::toInt($this->option('wait')));
        $key = 'chunky:doctor:probe:'.Str::uuid()->toString();
        $connection = $config->assemblyConnection ?? Coerce::toString($appConfig->get('queue.default'));
        $queue = $config->assemblyQueue ?? 'default';

        DoctorProbeJob::dispatch($key)
            ->onConnection($config->assemblyConnection)
            ->onQueue($config->assemblyQueue);

        $deadline = microtime(true) + $wait;

        while (true) {
            if ($cache->store()->pull($key) !== null) {
                $this->info("queue worker picked up the probe job on connection '{$connection}' / queue '{$queue}'.");

                return;
            }

            if (microtime(true) >= $deadline) {
                break;
            }

            usleep(100_000);
        }

        $this->reportError(
            "no queue worker picked up the probe on connection '{$connection}' / queue '{$queue}' within {$wait}s"
            .' — assembly jobs will never run. Start a worker: php artisan queue:work',
        );
    }

    private function checkBroadcasting(ChunkyConfig $config, BroadcastFactory $broadcast, Repository $appConfig): void
    {
        if (! $config->broadcastingEnabled) {
            $this->line('broadcasting disabled — clients poll the status endpoint (this is fine).');

            return;
        }

        $connection = Coerce::toString($appConfig->get('broadcasting.default'));
        $driver = Coerce::toString($appConfig->get("broadcasting.connections.{$connection}.driver"));

        if (in_array($driver, ['log', 'null', ''], true)) {
            $this->info("broadcasting enabled with the '".($driver === '' ? $connection : $driver)."' driver — events are not delivered to clients (fine for local).");

            return;
        }

        try {
            $broadcast->connection()->broadcast(
                [new PrivateChannel('chunky.doctor')],
                'chunky.doctor.probe',
                ['v' => 1],
            );
            $this->info("broadcast driver '{$driver}' accepted a test event on private-chunky.doctor.");
        } catch (Throwable $e) {
            $this->reportError("broadcast driver '{$driver}' failed to send a test event: {$e->getMessage()}");
        }
    }

    private function checkLocking(LockProvider $locks): void
    {
        try {
            $locks->withLock('chunky:doctor-probe', fn (): bool => true, 1);
            $this->info('locking works — acquired and released a probe lock.');
        } catch (Throwable $e) {
            $this->reportError("locking is broken: {$e->getMessage()}");
        }
    }

    private function checkTracker(ChunkyConfig $config, FilesystemFactory $filesystem): void
    {
        if ($config->tracker === 'database') {
            $missing = array_values(array_filter(
                ['chunky_uploads', 'chunky_batches'],
                fn (string $table): bool => ! Schema::hasTable($table),
            ));

            if ($missing === []) {
                $this->info('database tracker tables exist (chunky_uploads, chunky_batches).');
            } else {
                $this->reportError(
                    'database tracker table(s) missing: '.implode(', ', $missing)
                    .' — run: php artisan migrate',
                );
            }

            return;
        }

        try {
            $store = new JsonFileStore($filesystem->disk($config->chunkDisk));
            $probe = 'chunky/.doctor-tracker-probe.json';
            $store->write($probe, ['ok' => true]);
            $store->read($probe);
            $store->delete($probe);
            $this->info("filesystem tracker can read/write JSON state on the '{$config->chunkDisk}' disk.");
        } catch (Throwable $e) {
            $this->reportError("filesystem tracker cannot write state on the '{$config->chunkDisk}' disk: {$e->getMessage()}");
        }
    }

    private function reportError(string $message): void
    {
        $this->failed = true;
        $this->error($message);
    }
}
