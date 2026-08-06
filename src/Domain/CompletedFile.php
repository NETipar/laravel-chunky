<?php

declare(strict_types=1);

namespace NETipar\Chunky\Domain;

final readonly class CompletedFile
{
    public function __construct(
        public string $path,
        public int $size,
        public ?string $url = null,
    ) {}

    /**
     * The public wire shape (`file` object). `url` is omitted when absent so
     * clients can distinguish "no public URL" from an explicit null.
     *
     * @return array{path: string, size: int, url?: string}
     */
    public function toArray(): array
    {
        $data = ['path' => $this->path, 'size' => $this->size];

        if ($this->url !== null) {
            $data['url'] = $this->url;
        }

        return $data;
    }
}
