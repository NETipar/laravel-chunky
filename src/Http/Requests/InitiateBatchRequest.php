<?php

namespace NETipar\Chunky\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InitiateBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'total_files' => ['required', 'integer', 'min:1'],
            'context' => ['nullable', 'string', 'max:100'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
