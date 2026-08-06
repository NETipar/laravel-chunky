<?php

declare(strict_types=1);

namespace NETipar\Chunky\Adapters\Filesystem;

use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * Thin JSON-on-disk persistence used by the filesystem repositories. Writers are
 * serialized by the repositories' LockProvider, so this layer stays simple.
 */
final class JsonFileStore
{
    public function __construct(
        private readonly Filesystem $disk,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function read(string $path): ?array
    {
        if (! $this->disk->exists($path)) {
            return null;
        }

        $contents = $this->disk->get($path);

        if (! is_string($contents) || $contents === '') {
            return null;
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function write(string $path, array $data): void
    {
        $this->disk->put($path, (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function delete(string $path): void
    {
        if ($this->disk->exists($path)) {
            $this->disk->delete($path);
        }
    }

    /**
     * @return list<string>
     */
    public function list(string $directory): array
    {
        return array_values($this->disk->files($directory));
    }
}
