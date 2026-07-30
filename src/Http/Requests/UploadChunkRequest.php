<?php

declare(strict_types=1);

namespace NETipar\Chunky\Http\Requests;

use NETipar\Chunky\Authorization\Authorizer;
use NETipar\Chunky\Services\UploadService;
use NETipar\Chunky\Support\Coerce;

class UploadChunkRequest extends AbstractChunkyRequest
{
    public function authorize(): bool
    {
        $upload = app(UploadService::class)->find(Coerce::toString($this->route('uploadId')));

        // Unknown upload: let the controller/service answer 404; a non-owner of
        // an existing upload is rejected with 403.
        return $upload === null || app(Authorizer::class)->owns($this->user(), $upload);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $upload = app(UploadService::class)->find(Coerce::toString($this->route('uploadId')));

        $indexRules = ['required', 'integer', 'min:0'];

        if ($upload !== null) {
            $indexRules[] = 'max:'.($upload->totalChunks - 1);
        }

        return [
            'chunk' => ['required', 'file'],
            'chunk_index' => $indexRules,
            'checksum' => ['nullable', 'string'],
        ];
    }
}
