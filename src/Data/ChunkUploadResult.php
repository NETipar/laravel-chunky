<?php

namespace NETipar\Chunky\Data;

class ChunkUploadResult
{
    public function __construct(
        public readonly bool $isComplete,
        public readonly UploadMetadata $metadata,
    ) {}
}
