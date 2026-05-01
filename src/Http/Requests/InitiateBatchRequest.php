<?php

declare(strict_types=1);

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
        $maxFiles = (int) config('chunky.limits.max_files_per_batch', 100);

        return [
            'total_files' => ['required', 'integer', 'min:1', "max:{$maxFiles}"],
            'context' => ['nullable', 'string', 'max:100'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
