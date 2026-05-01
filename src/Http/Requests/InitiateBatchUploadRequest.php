<?php

namespace NETipar\Chunky\Http\Requests;

use Illuminate\Validation\ValidationException;
use NETipar\Chunky\Authorization\AuthorizesChunkyRequests;
use NETipar\Chunky\ChunkyManager;

class InitiateBatchUploadRequest extends InitiateUploadRequest
{
    use AuthorizesChunkyRequests;

    public function authorize(): bool
    {
        return $this->userOwnsBatch();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        // For batch uploads, the validation rules MUST be the ones declared
        // by the batch's context — otherwise a caller could send a different
        // `context` value in the request and slip past the rules of the
        // actual save callback that will run (validation bypass).
        $batchContext = $this->resolveBatchContext();

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

        $allowedMimes = config('chunky.allowed_mimes', []);

        if (! empty($allowedMimes)) {
            $rules['mime_type'][] = 'in:'.implode(',', $allowedMimes);
        }

        if ($batchContext !== null) {
            $rules = $this->mergeContextRulesFor($rules, $batchContext);
        }

        return $rules;
    }

    private function resolveBatchContext(): ?string
    {
        /** @var string|null $batchId */
        $batchId = $this->route('batchId');

        if (! $batchId) {
            return null;
        }

        $batch = app(ChunkyManager::class)->getBatchStatus($batchId);

        if (! $batch) {
            return null;
        }

        // A finalised batch refuses additional uploads at the manager layer
        // (see validateBatchExists). Surface that as a validation error here
        // so the caller gets a clean 422 instead of a 500.
        if ($batch->status->isTerminal()) {
            throw ValidationException::withMessages([
                'batch' => ["Batch {$batchId} is no longer accepting uploads (status: {$batch->status->value})."],
            ]);
        }

        return $batch->context;
    }

    /**
     * @param  array<string, array<int, mixed>>  $rules
     * @return array<string, array<int, mixed>>
     */
    private function mergeContextRulesFor(array $rules, string $context): array
    {
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
