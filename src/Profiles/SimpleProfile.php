<?php

declare(strict_types=1);

namespace NETipar\Chunky\Profiles;

/**
 * A configuration-only profile: a fixed directory, optional disk, size cap, and
 * mime allow-list. Backs Chunky::simple(). The assembly pipeline moves the file
 * into directory(); there is no custom completed() hook.
 */
final class SimpleProfile extends UploadProfile
{
    /**
     * @param  list<string>  $mimes
     */
    public function __construct(
        private readonly string $directory,
        private readonly ?string $disk = null,
        private readonly ?int $maxFileSize = null,
        private readonly array $mimes = [],
    ) {}

    public function directory(UploadContext $context): string
    {
        return trim($this->directory, '/');
    }

    public function disk(): ?string
    {
        return $this->disk;
    }

    public function maxFileSize(): ?int
    {
        return $this->maxFileSize;
    }

    public function rules(): array
    {
        $rules = [];

        if ($this->maxFileSize !== null) {
            $rules['file_size'] = ['max:'.$this->maxFileSize];
        }

        if ($this->mimes !== []) {
            $rules['mime_type'] = ['in:'.implode(',', $this->mimes)];
        }

        return $rules;
    }
}
