<?php

namespace NETipar\Chunky\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use NETipar\Chunky\ChunkyManager;

class BatchStatusController extends Controller
{
    public function __invoke(string $batchId, ChunkyManager $manager): JsonResponse
    {
        $batch = $manager->getBatchStatus($batchId);

        if (! $batch) {
            return response()->json(['message' => 'Batch not found.'], 404);
        }

        return response()->json($batch->toArray());
    }
}
