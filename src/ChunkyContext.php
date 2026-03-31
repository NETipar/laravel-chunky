<?php

namespace NETipar\Chunky;

use NETipar\Chunky\Data\UploadMetadata;

abstract class ChunkyContext
{
    abstract public function name(): string;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }

    public function save(UploadMetadata $metadata): void {}
}
