<?php

declare(strict_types=1);

namespace NETipar\Chunky\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use NETipar\Chunky\Authorization\Authorizer;
use NETipar\Chunky\ChunkyManager;

class BatchStatusController extends Controller
{
    public function __invoke(string $batchId, ChunkyManager $manager, Authorizer $authorizer): JsonResponse
    {
        $batch = $manager->getBatchStatus($batchId);

        if (! $batch) {
            return response()->json(['message' => 'Batch not found.'], 404);
        }

        if (! $authorizer->canAccessBatch(auth()->user(), $batch)) {
            return response()->json(['message' => 'Batch not found.'], 404);
        }

        return response()->json($batch->toArray());
    }
}
