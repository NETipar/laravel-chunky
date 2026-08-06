<?php

declare(strict_types=1);

namespace NETipar\Chunky\Http\Controllers\Concerns;

use Illuminate\Contracts\Auth\Authenticatable;
use NETipar\Chunky\Authorization\Authorizer;
use NETipar\Chunky\Domain\BatchRecord;
use NETipar\Chunky\Exceptions\BatchNotFoundException;
use NETipar\Chunky\Services\BatchService;

trait ResolvesOwnedBatch
{
    protected function ownedBatchOr404(
        BatchService $batches,
        Authorizer $authorizer,
        ?Authenticatable $user,
        string $batchId,
    ): BatchRecord {
        $batch = $batches->find($batchId);

        if ($batch === null || ! $authorizer->ownsBatch($user, $batch)) {
            throw BatchNotFoundException::forBatch($batchId);
        }

        return $batch;
    }
}
