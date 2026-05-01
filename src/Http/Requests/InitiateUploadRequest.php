<?php

declare(strict_types=1);

namespace NETipar\Chunky\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use NETipar\Chunky\ChunkyManager;
use NETipar\Chunky\Support\ChunkCalculator;

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
        // file_name regex: rejects path-traversal characters (`/`, `\`, NUL,
        // Windows reserved chars) and the `.` / `..` directory references.
        // The handler additionally applies basename() as a defence-in-depth
        // measure when assembling the final path.
        $maxMetadataKeys = (int) config('chunky.metadata.max_keys', 50);

        $rules = [
            'file_name' => [
                'required',
                'string',
                'max:255',
                'regex:/^[^\/\\\\\x00:*?"<>|]+$/u',
                'not_in:.,..',
            ],
            'file_size' => ['required', 'integer', 'min:1'],
            'mime_type' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array', "max:{$maxMetadataKeys}"],
            'context' => ['nullable', 'string', 'max:100'],
        ];

        $maxFileSize = config('chunky.max_file_size', 0);

        if ($maxFileSize > 0) {
            $rules['file_size'][] = "max:{$maxFileSize}";
        }

        $rules['file_size'][] = function (string $attr, mixed $value, \Closure $fail): void {
            $maxChunks = (int) config('chunky.max_chunks_per_upload', 100_000);

            if ($maxChunks <= 0) {
                return;
            }

            $size = (int) $value;
            $chunkSize = ChunkCalculator::chunkSize();
            $totalChunks = ChunkCalculator::totalChunks($size, $chunkSize);

            if ($totalChunks > $maxChunks) {
                $fail(
                    "The file would require {$totalChunks} chunks "
                    ."(file_size {$size}, chunk_size {$chunkSize}), exceeding the "
                    ."limit of {$maxChunks}. Increase chunky.chunk_size or "
                    .'chunky.max_chunks_per_upload, or upload a smaller file.'
                );
            }
        };

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
