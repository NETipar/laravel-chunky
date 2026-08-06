<?php

declare(strict_types=1);

namespace NETipar\Chunky\Services\Assembly;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use NETipar\Chunky\Ports\ChunkStore;
use NETipar\Chunky\Services\Assembly\Steps\CleanupStep;
use NETipar\Chunky\Services\Assembly\Steps\IntegrityStep;
use NETipar\Chunky\Services\Assembly\Steps\MergeStep;
use NETipar\Chunky\Services\Assembly\Steps\MoveStep;
use NETipar\Chunky\Services\Assembly\Steps\PreflightStep;
use NETipar\Chunky\Services\Assembly\Steps\ProfileStep;

final class AssemblyPipelineFactory
{
    public function __construct(
        private readonly ChunkStore $chunks,
        private readonly FilesystemFactory $filesystem,
    ) {}

    public function forDisk(string $disk): AssemblyPipeline
    {
        $target = $this->filesystem->disk($disk);

        return new AssemblyPipeline([
            new PreflightStep($this->chunks),
            new MergeStep($this->chunks, $target),
            new IntegrityStep($target),
            new MoveStep($target),
            new ProfileStep,
            new CleanupStep($this->chunks),
        ]);
    }
}
