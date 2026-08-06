<?php

declare(strict_types=1);

namespace NETipar\Chunky\Http\Requests;

use NETipar\Chunky\Authorization\Authorizer;
use NETipar\Chunky\Services\UploadService;
use NETipar\Chunky\Support\Coerce;

class PartUrlsRequest extends AbstractChunkyRequest
{
    public function authorize(): bool
    {
        $upload = app(UploadService::class)->find(Coerce::toString($this->route('uploadId')));

        return $upload === null || app(Authorizer::class)->owns($this->user(), $upload);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'indexes' => ['required', 'array', 'min:1', 'max:100'],
            'indexes.*' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * @return list<int>
     */
    public function indexes(): array
    {
        /** @var array<int, mixed> $raw */
        $raw = $this->input('indexes', []);

        return array_values(array_map(static fn (mixed $index): int => Coerce::toInt($index), $raw));
    }
}
