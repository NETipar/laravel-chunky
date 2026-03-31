<?php

namespace NETipar\Chunky\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use NETipar\Chunky\ChunkyManager;

class InitiateUploadRequest extends FormRequest
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
        $rules = [
            'file_name' => ['required', 'string', 'max:255'],
            'file_size' => ['required', 'integer', 'min:1'],
            'mime_type' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
            'context' => ['nullable', 'string', 'max:100'],
        ];

        $maxFileSize = config('chunky.max_file_size', 0);

        if ($maxFileSize > 0) {
            $rules['file_size'][] = "max:{$maxFileSize}";
        }

        $allowedMimes = config('chunky.allowed_mimes', []);

        if (! empty($allowedMimes)) {
            $rules['mime_type'][] = 'in:'.implode(',', $allowedMimes);
        }

        return $this->mergeContextRules($rules);
    }

    /**
     * @param  array<string, array<int, mixed>>  $rules
     * @return array<string, array<int, mixed>>
     */
    private function mergeContextRules(array $rules): array
    {
        $context = $this->input('context');

        if (! $context) {
            return $rules;
        }

        $manager = app(ChunkyManager::class);

        if (! $manager->hasContext($context)) {
            throw ValidationException::withMessages([
                'context' => ["The context '{$context}' is not registered."],
            ]);
        }

        $contextRules = $manager->getContextRules($context);

        foreach ($contextRules as $field => $fieldRules) {
            if (isset($rules[$field])) {
                $rules[$field] = array_merge($rules[$field], (array) $fieldRules);
            } else {
                $rules[$field] = (array) $fieldRules;
            }
        }

        return $rules;
    }
}
