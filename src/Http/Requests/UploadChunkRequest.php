<?php

namespace NETipar\Chunky\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadChunkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'chunk' => ['required', 'file'],
            'chunk_index' => ['required', 'integer', 'min:0'],
            'checksum' => ['nullable', 'string'],
        ];
    }
}
