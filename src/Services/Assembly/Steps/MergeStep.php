<?php

declare(strict_types=1);

namespace NETipar\Chunky\Services\Assembly\Steps;

use Illuminate\Contracts\Filesystem\Filesystem;
use NETipar\Chunky\Exceptions\ChunkyException;
use NETipar\Chunky\Ports\ChunkStore;
use NETipar\Chunky\Services\Assembly\AssemblyState;
use NETipar\Chunky\Services\Assembly\AssemblyStep;

/**
 * Stream-concatenates the chunks into a staging file on the target disk via a
 * local temp file — never loading the whole upload into memory.
 */
final class MergeStep implements AssemblyStep
{
    public function __construct(
        private readonly ChunkStore $chunks,
        private readonly Filesystem $disk,
    ) {}

    public function execute(AssemblyState $state): void
    {
        $temp = tmpfile();

        if ($temp === false) {
            throw new ChunkyException('Could not open a temporary file for assembly.');
        }

        try {
            // Hash while copying so the whole-file checksum needs no second
            // pass over the assembled bytes.
            $hash = hash_init('sha256');

            for ($index = 0; $index < $state->record->totalChunks; $index++) {
                $chunkStream = $this->chunks->readStream($state->record->uploadId, $index);

                while (! feof($chunkStream)) {
                    $buffer = fread($chunkStream, 1024 * 1024);

                    if ($buffer === false || $buffer === '') {
                        break;
                    }

                    fwrite($temp, $buffer);
                    hash_update($hash, $buffer);
                }

                fclose($chunkStream);
            }

            $state->computedChecksum = hash_final($hash);

            rewind($temp);

            $staging = 'chunky/staging/'.$state->record->uploadId.'.part';
            $this->disk->writeStream($staging, $temp);
            $state->stagingPath = $staging;
        } finally {
            if (is_resource($temp)) {
                fclose($temp);
            }
        }
    }

    public function compensate(AssemblyState $state): void
    {
        if ($state->stagingPath !== null && $this->disk->exists($state->stagingPath)) {
            $this->disk->delete($state->stagingPath);
        }
    }
}
