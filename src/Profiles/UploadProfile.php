<?php

declare(strict_types=1);

namespace NETipar\Chunky\Profiles;

use Illuminate\Support\Str;

/**
 * A named upload profile: validation rules, destination, authorization, and a
 * post-assembly hook. Class-based (IDE-friendly, testable) — the v1 replacement
 * for the 0.x context-registry callbacks.
 */
abstract class UploadProfile
{
    /**
     * Extra validation rules merged into the initiate request (keyed by
     * file_name / file_size / mime_type / metadata).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Target disk for the final file. Null uses the configured default disk.
     */
    public function disk(): ?string
    {
        return null;
    }

    /**
     * Destination directory (relative to the disk) for the assembled file.
     */
    abstract public function directory(UploadContext $context): string;

    public function authorize(UploadContext $context): bool
    {
        return true;
    }

    /**
     * The stored file name. Defaults to a UUID keeping the original extension.
     */
    public function fileName(UploadContext $context, string $originalName): string
    {
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);

        return Str::uuid()->toString().($extension !== '' ? '.'.$extension : '');
    }

    /**
     * Runs after the file is assembled and moved into place, before the upload
     * is marked completed. The return value becomes the response `payload`.
     *
     * @return array<string, mixed>|null
     */
    public function completed(CompletedUpload $upload): ?array
    {
        return null;
    }

    /**
     * Max accepted file size in bytes. Null uses the configured limit.
     */
    public function maxFileSize(): ?int
    {
        return null;
    }
}
