<?php

declare(strict_types=1);

namespace NETipar\Chunky\Console;

use Illuminate\Console\Command;
use NETipar\Chunky\Contracts\ChunkHandler;
use NETipar\Chunky\Contracts\UploadTracker;

class CleanupCommand extends Command
{
    protected $signature = 'chunky:cleanup {--dry-run : List the upload IDs that would be removed without deleting anything}';

    protected $description = 'Remove expired Chunky uploads (chunks + tracker metadata).';

    public function handle(UploadTracker $tracker, ChunkHandler $handler): int
    {
        $expired = $tracker->expiredUploadIds();

        if ($expired === []) {
            $this->info('No expired uploads found.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        foreach ($expired as $uploadId) {
            if ($dryRun) {
                $this->line("Would remove: {$uploadId}");

                continue;
            }

            $this->line("Removing {$uploadId}...");

            try {
                $handler->cleanup($uploadId);
                $tracker->forget($uploadId);
            } catch (\Throwable $e) {
                $this->error("Failed to remove {$uploadId}: {$e->getMessage()}");
            }
        }

        $this->info(sprintf('%s %d upload(s).', $dryRun ? 'Would remove' : 'Removed', count($expired)));

        return self::SUCCESS;
    }
}
