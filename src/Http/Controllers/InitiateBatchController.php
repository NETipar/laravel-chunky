<?php

declare(strict_types=1);

namespace NETipar\Chunky\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use NETipar\Chunky\ChunkyManager;
use NETipar\Chunky\Http\Requests\InitiateBatchRequest;

class InitiateBatchController extends Controller
{
    public function __invoke(InitiateBatchRequest $request, ChunkyManager $manager): JsonResponse
    {
        $batch = $manager->initiateBatch(
            totalFiles: (int) $request->validated('total_files'),
            context: $request->validated('context'),
            metadata: $request->validated('metadata') ?? [],
        );

        return response()->json(['batch_id' => $batch->batchId], 201);
    }
}
