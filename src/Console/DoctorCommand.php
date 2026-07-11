<?php

declare(strict_types=1);

namespace NETipar\Chunky\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use NETipar\Chunky\Config\ChunkyConfig;
use Throwable;

final class DoctorCommand extends Command
{
    protected $signature = 'chunky:doctor';

    protected $description = 'Diagnose the Chunky setup (disks, assembly mode, broadcasting).';

    public function handle(ChunkyConfig $config, FilesystemFactory $filesystem): int
    {
        $this->line('Chunky configuration');
        $this->line("  tracker:        {$config->tracker}");
        $this->line("  final disk:     {$config->disk}");
        $this->line("  chunk disk:     {$config->chunkDisk}");
        $this->line("  assembly mode:  {$config->assemblyMode}");
        $this->line('  locking driver: '.$config->lockingDriver);
        $this->newLine();

        $this->checkDiskWritable($filesystem, $config->chunkDisk, 'chunk disk');
        $this->checkDiskWritable($filesystem, $config->disk, 'final disk');

        if ($config->assemblyMode !== 'sync') {
            $this->warn('assembly.mode is not "sync" — make sure a queue worker is running to assemble files.');
        } else {
            $this->info('assembly.mode=sync — no queue worker required.');
        }

        if ($config->broadcastingEnabled) {
            $this->info('broadcasting enabled — completion events are pushed over your broadcast driver.');
        } else {
            $this->line('broadcasting disabled — clients poll the status endpoint (this is fine).');
        }

        return self::SUCCESS;
    }

    private function checkDiskWritable(FilesystemFactory $filesystem, string $disk, string $label): void
    {
        try {
            $probe = 'chunky/.doctor-probe';
            $filesystem->disk($disk)->put($probe, 'ok');
            $filesystem->disk($disk)->delete($probe);
            $this->info("{$label} '{$disk}' is writable.");
        } catch (Throwable $e) {
            $this->error("{$label} '{$disk}' is NOT writable: {$e->getMessage()}");
        }
    }
}
