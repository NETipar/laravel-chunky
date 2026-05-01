<?php

declare(strict_types=1);

namespace NETipar\Chunky\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use NETipar\Chunky\Rules\ValidMetadata;
use NETipar\Chunky\Support\ChunkCalculator;
use NETipar\Chunky\Support\ContextRegistry;

/**
 * Shared baseline for the two initiate request classes. Holds the
 * regex/size/mime rules and the context-rule merge helper so adding a
 * new constraint touches one place instead of two.
 */
abstract class AbstractInitiateUploadRequest extends FormRequest
{
    /**
     * Build the baseline `file_name` / `file_size` / `mime_type` /
     * `metadata` / `context` rule set from config.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function baseUploadRules(): array
    {
        $maxMetadataKeys = (int) config('chunky.metadata.max_keys', 50);

        $rules = [
            // file_name regex: rejects path-traversal characters (`/`, `\`,
            // NUL, Windows reserved chars) and the `.` / `..` directory
            // references. The handler additionally applies basename() as a
            // defence-in-depth measure when assembling the final path.
            'file_name' => [
                'required',
                'string',
                'max:255',
                'regex:/^[^\/\\\\\x00:*?"<>|]+$/u',
                'not_in:.,..',
            ],
            'file_size' => ['required', 'integer', 'min:1'],
            'mime_type' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array', "max:{$maxMetadataKeys}", new ValidMetadata],
            'context' => ['nullable', 'string', 'max:100'],
        ];

        $maxFileSize = config('chunky.limits.max_file_size', 0);

        if ($maxFileSize > 0) {
            $rules['file_size'][] = "max:{$maxFileSize}";
        }

        // Reject pathological initiate requests that would require more
        // chunks than the configured cap (default 100k). A 1TB filesize
        // with a 1KB chunk_size would otherwise create a billion-row
        // tracker entry and a 0.0001%-step UI.
        $rules['file_size'][] = function (string $attr, mixed $value, \Closure $fail): void {
            $maxChunks = (int) config('chunky.limits.max_chunks_per_upload', 100_000);

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

        $allowedMimes = config('chunky.limits.allowed_mimes', []);

        if (! empty($allowedMimes)) {
            $rules['mime_type'][] = 'in:'.implode(',', $allowedMimes);
        }

        return $rules;
    }

    /**
     * Merge in the rules registered for the named context, throwing
     * 422 if the context is unknown.
     *
     * @param  array<string, array<int, mixed>>  $rules
     * @return array<string, array<int, mixed>>
     */
    protected function applyContextRules(array $rules, ?string $context): array
    {
        if ($context === null || $context === '') {
            return $rules;
        }

        $registry = app(ContextRegistry::class);

        if (! $registry->has($context)) {
            throw ValidationException::withMessages([
                'context' => ["The context '{$context}' is not registered."],
            ]);
        }

        foreach ($registry->rules($context) as $field => $fieldRules) {
            $rules[$field] = isset($rules[$field])
                ? array_merge($rules[$field], (array) $fieldRules)
                : (array) $fieldRules;
        }

        return $rules;
    }
}
