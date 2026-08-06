<?php

declare(strict_types=1);

namespace NETipar\Chunky\Http\Requests;

use NETipar\Chunky\Profiles\ProfileRegistry;
use NETipar\Chunky\Services\BatchService;
use NETipar\Chunky\Support\Coerce;

/**
 * Validates a batch member upload against the BATCH's profile, not the request's
 * — closing the context-bypass hole from 0.x.
 */
class InitiateBatchUploadRequest extends AbstractChunkyRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $batchId = Coerce::toString($this->route('batchId'));
        $batch = app(BatchService::class)->find($batchId);
        $profile = $batch !== null
            ? app(ProfileRegistry::class)->resolve($batch->profile)
            : null;

        return $this->initiateRules($profile);
    }
}
