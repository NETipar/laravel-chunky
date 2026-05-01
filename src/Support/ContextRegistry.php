<?php

declare(strict_types=1);

namespace NETipar\Chunky\Support;

use Closure;
use Illuminate\Support\Facades\Storage;
use NETipar\Chunky\ChunkyContext;
use NETipar\Chunky\Data\UploadMetadata;
use NETipar\Chunky\Exceptions\ChunkyException;

/**
 * Holds the runtime registry of upload contexts (validation rules + save
 * callbacks) keyed by name. Extracted from ChunkyManager so:
 * - Contexts can be registered without resolving the full manager.
 * - Tests can drop a stub registry into the container.
 * - Future entries (e.g. inspector / list-contexts artisan command) have
 *   a single point of truth.
 */
class ContextRegistry
{
    /** @var array<string, array{rules: ?Closure, save: ?Closure}> */
    private array $contexts = [];

    /**
     * Register a class-based upload context.
     *
     * @param  class-string<ChunkyContext>  $contextClass
     */
    public function registerClass(string $contextClass): void
    {
        $instance = app($contextClass);

        $this->register(
            name: $instance->name(),
            rules: fn () => $instance->rules(),
            save: fn (UploadMetadata $metadata) => $instance->save($metadata),
        );
    }

    /**
     * Quick context registration: validates and moves the file to the given directory.
     *
     * @param  array{max_size?: int, mimes?: array<int, string>}  $options
     */
    public function registerSimple(string $name, string $directory, array $options = []): void
    {
        $rules = null;

        if (! empty($options['max_size']) || ! empty($options['mimes'])) {
            $rules = function () use ($options) {
                $r = [];

                if (! empty($options['max_size'])) {
                    $r['file_size'] = ["max:{$options['max_size']}"];
                }

                if (! empty($options['mimes'])) {
                    $r['mime_type'] = ['in:'.implode(',', $options['mimes'])];
                }

                return $r;
            };
        }

        $this->register(
            name: $name,
            rules: $rules,
            save: function (UploadMetadata $metadata) use ($directory): void {
                $disk = Storage::disk($metadata->disk);
                // Defence-in-depth: even though InitiateUploadRequest's
                // file_name regex blocks path-traversal characters and the
                // assembler applies basename(), guard the destination here
                // too in case a custom request layer slips one through.
                $safeName = basename($metadata->fileName);

                if ($safeName === '' || $safeName === '.' || $safeName === '..') {
                    throw new ChunkyException(
                        "Refusing to move upload {$metadata->uploadId}: invalid file name."
                    );
                }

                $destination = rtrim($directory, '/')."/{$safeName}";
                $disk->move($metadata->finalPath, $destination);
            },
        );
    }

    /**
     * Register validation rules and/or save handler for an upload context.
     *
     * @param  ?Closure(): array<string, array<int, mixed>>  $rules
     * @param  ?Closure(UploadMetadata $metadata): void  $save
     */
    public function register(string $name, ?Closure $rules = null, ?Closure $save = null): void
    {
        if (trim($name) === '') {
            throw new ChunkyException('Context name must be a non-empty string.');
        }

        $this->contexts[$name] = [
            'rules' => $rules,
            'save' => $save,
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(string $name): array
    {
        if (! isset($this->contexts[$name]['rules'])) {
            return [];
        }

        return ($this->contexts[$name]['rules'])();
    }

    public function saveCallback(string $name): ?Closure
    {
        return $this->contexts[$name]['save'] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->contexts[$name]);
    }

    /**
     * @return array<int, string>
     */
    public function names(): array
    {
        return array_keys($this->contexts);
    }
}
