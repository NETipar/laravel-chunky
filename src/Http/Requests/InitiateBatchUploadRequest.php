<?php

declare(strict_types=1);

namespace NETipar\Chunky\Http\Requests;

use Illuminate\Validation\ValidationException;
use NETipar\Chunky\Authorization\AuthorizesChunkyRequests;
use NETipar\Chunky\ChunkyManager;

class InitiateBatchUploadRequest extends AbstractInitiateUploadRequest
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
        return $this->applyContextRules(
            $this->baseUploadRules(),
            $this->resolveBatchContext(),
        );
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
        // (see assertBatchAcceptsUploads). Surface that as a validation
        // error here so the caller gets a clean 422 instead of a 500.
        if ($batch->status->isTerminal()) {
            throw ValidationException::withMessages([
                'batch' => ["Batch {$batchId} is no longer accepting uploads (status: {$batch->status->value})."],
            ]);
        }

        return $batch->context;
    }
}
