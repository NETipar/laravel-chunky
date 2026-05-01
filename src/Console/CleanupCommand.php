<?php

declare(strict_types=1);

namespace NETipar\Chunky\Console;

use Illuminate\Console\Command;
use NETipar\Chunky\Contracts\BatchTracker;
use NETipar\Chunky\Contracts\ChunkHandler;
use NETipar\Chunky\Contracts\UploadTracker;

class CleanupCommand extends Command
{
    /** @var string */
    protected $signature = 'chunky:cleanup
        {--dry-run : List the upload / batch IDs that would be removed without deleting anything}
        {--limit= : Stop after this many removals (per kind)}
        {--skip-uploads : Skip the per-upload sweep (only clean orphaned batches)}
        {--skip-batches : Skip the per-batch sweep (only clean orphaned uploads)}';

    /** @var string */
    protected $description = 'Remove expired Chunky uploads and batches (chunks + tracker metadata).';

    public function handle(
        UploadTracker $tracker,
        BatchTracker $batchTracker,
        ChunkHandler $handler,
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $skipUploads = (bool) $this->option('skip-uploads');
        $skipBatches = (bool) $this->option('skip-batches');

        $totalUploads = 0;
        $totalBatches = 0;

        if (! $skipUploads) {
            $totalUploads = $this->cleanupUploads($tracker, $handler, $dryRun, $limit);
        }

        if (! $skipBatches) {
            $totalBatches = $this->cleanupBatches($batchTracker, $dryRun, $limit);
        }

        if ($totalUploads === 0 && $totalBatches === 0) {
            $this->info('No expired uploads or batches found.');
        } else {
            $this->info(sprintf(
                '%s %d upload(s) and %d batch(es).',
                $dryRun ? 'Would remove' : 'Removed',
                $totalUploads,
                $totalBatches,
            ));
        }

        return self::SUCCESS;
    }

    private function cleanupUploads(
        UploadTracker $tracker,
        ChunkHandler $handler,
        bool $dryRun,
        ?int $limit,
    ): int {
        $expired = $tracker->expiredUploadIds();

        if ($limit !== null && $limit > 0) {
            $expired = array_slice($expired, 0, $limit);
        }

        $count = 0;

        foreach ($expired as $uploadId) {
            if ($dryRun) {
                $this->line("Would remove upload: {$uploadId}");
                $count++;

                continue;
            }

            $this->line("Removing upload {$uploadId}...");

            try {
                $handler->cleanup($uploadId);
                $tracker->forget($uploadId);
                $count++;
            } catch (\Throwable $e) {
                $this->error("Failed to remove upload {$uploadId}: {$e->getMessage()}");
            }
        }

        return $count;
    }

    private function cleanupBatches(BatchTracker $batchTracker, bool $dryRun, ?int $limit): int
    {
        $expired = $batchTracker->expiredBatchIds();

        if ($limit !== null && $limit > 0) {
            $expired = array_slice($expired, 0, $limit);
        }

        $count = 0;

        foreach ($expired as $batchId) {
            if ($dryRun) {
                $this->line("Would remove batch: {$batchId}");
                $count++;

                continue;
            }

            $this->line("Removing batch {$batchId}...");

            try {
                $batchTracker->forget($batchId);
                $count++;
            } catch (\Throwable $e) {
                $this->error("Failed to remove batch {$batchId}: {$e->getMessage()}");
            }
        }

        return $count;
    }
}
