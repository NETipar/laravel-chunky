<?php

declare(strict_types=1);

namespace NETipar\Chunky\Http\Requests;

use NETipar\Chunky\Authorization\Authorizer;
use NETipar\Chunky\Services\UploadService;
use NETipar\Chunky\Support\Coerce;

class CompleteDirectUploadRequest extends AbstractChunkyRequest
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
            'parts' => ['required', 'array', 'min:1', 'max:10000'],
            'parts.*.index' => ['required', 'integer', 'min:0'],
            'parts.*.etag' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<int, string> index => ETag
     */
    public function parts(): array
    {
        $parts = [];

        /** @var array<int, mixed> $raw */
        $raw = $this->input('parts', []);

        foreach ($raw as $part) {
            if (! is_array($part)) {
                continue;
            }

            $parts[Coerce::toInt($part['index'] ?? 0)] = Coerce::toString($part['etag'] ?? '');
        }

        return $parts;
    }
}
