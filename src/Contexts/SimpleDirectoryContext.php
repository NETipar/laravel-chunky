<?php

declare(strict_types=1);

namespace NETipar\Chunky\Contexts;

use Illuminate\Support\Facades\Storage;
use NETipar\Chunky\ChunkyContext;
use NETipar\Chunky\Data\UploadMetadata;
use NETipar\Chunky\Exceptions\ChunkyException;

/**
 * Class form of the registry's "simple directory" context. Validates
 * size + mime, then moves the assembled file from
 * `chunky/uploads/{uploadId}/{name}` into the configured directory.
 *
 * `ContextRegistry::registerSimple()` instantiates this class — it is
 * therefore the canonical way to extend the simple flow (subclass and
 * register manually, or override the `save()` step entirely). Without
 * it the simple-context implementation lived inside ChunkyManager as
 * an inline closure, which made it impossible to test in isolation.
 *
 * @phpstan-type SimpleOptions array{max_size?: int, mimes?: array<int, string>}
 */
class SimpleDirectoryContext extends ChunkyContext
{
    /**
     * @param  SimpleOptions  $options
     */
    public function __construct(
        private string $contextName,
        private string $directory,
        private array $options = [],
    ) {}

    public function name(): string
    {
        return $this->contextName;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [];

        if (! empty($this->options['max_size'])) {
            $rules['file_size'] = ["max:{$this->options['max_size']}"];
        }

        if (! empty($this->options['mimes'])) {
            $rules['mime_type'] = ['in:'.implode(',', $this->options['mimes'])];
        }

        return $rules;
    }

    public function save(UploadMetadata $metadata): void
    {
        $disk = Storage::disk($metadata->disk);

        // Defence-in-depth: even though InitiateUploadRequest's file_name
        // regex blocks path-traversal characters and the assembler
        // applies basename(), guard the destination here too in case a
        // custom request layer slips one through.
        $safeName = basename($metadata->fileName);

        if ($safeName === '' || $safeName === '.' || $safeName === '..') {
            throw new ChunkyException(
                "Refusing to move upload {$metadata->uploadId}: invalid file name."
            );
        }

        $destination = rtrim($this->directory, '/')."/{$safeName}";
        $disk->move($metadata->finalPath, $destination);
    }
}
