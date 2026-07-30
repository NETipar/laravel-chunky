<?php

declare(strict_types=1);

namespace NETipar\Chunky\Console;

use Illuminate\Console\Command;
use NETipar\Chunky\Ports\ChunkStore;
use NETipar\Chunky\Ports\Clock;
use NETipar\Chunky\Ports\UploadRepository;
use NETipar\Chunky\Services\DirectUploadService;

final class CleanupCommand extends Command
{
    protected $signature = 'chunky:cleanup {--dry-run : List what would be removed without deleting}';

    protected $description = 'Remove expired, unfinished chunk uploads and their temporary chunks.';

    public function handle(UploadRepository $uploads, ChunkStore $chunks, Clock $clock, DirectUploadService $direct): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $now = $clock->now();
        $processed = 0;

        while (true) {
            $expired = $uploads->findExpired($now, 100);

            if ($expired === []) {
                break;
            }

            foreach ($expired as $upload) {
                $this->info(($dryRun ? 'Would remove upload: ' : 'Removing upload: ').$upload->uploadId);

                if (! $dryRun) {
                    $chunks->purge($upload->uploadId);
                    $direct->abortRemote($upload);
                    $uploads->delete($upload->uploadId);
                }

                $processed++;
            }

            // In dry-run nothing is deleted, so the same rows would repeat.
            if ($dryRun) {
                break;
            }
        }

        if ($processed === 0) {
            $this->info('No expired uploads found.');
        } else {
            $this->comment("Processed {$processed} expired upload(s).");
        }

        return self::SUCCESS;
    }
}
