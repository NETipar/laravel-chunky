<?php

declare(strict_types=1);

namespace NETipar\Chunky\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use NETipar\Chunky\Authorization\Authorizer;
use NETipar\Chunky\Http\Controllers\Concerns\ResolvesOwnedBatch;
use NETipar\Chunky\Services\BatchService;

final class CancelBatchController
{
    use ResolvesOwnedBatch;

    public function __invoke(
        Request $request,
        string $batchId,
        BatchService $batches,
        Authorizer $authorizer,
    ): JsonResponse {
        $this->ownedBatchOr404($batches, $authorizer, $request->user(), $batchId);

        $batches->cancel($batchId);

        return new JsonResponse(['status' => 'cancelled']);
    }
}
