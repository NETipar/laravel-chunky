<?php

declare(strict_types=1);

namespace NETipar\Chunky\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use NETipar\Chunky\Authorization\Authorizer;
use NETipar\Chunky\ChunkyManager;
use Symfony\Component\HttpFoundation\Response;

class CancelBatchController extends Controller
{
    public function __invoke(string $batchId, ChunkyManager $manager, Authorizer $authorizer): JsonResponse
    {
        $batch = $manager->getBatchStatus($batchId);

        if (! $batch) {
            return response()->json(
                ['message' => __('chunky::chunky.http.batch_not_found')],
                Response::HTTP_NOT_FOUND,
            );
        }

        if (! $authorizer->canCancelBatch(auth()->user(), $batch)) {
            // Hide the batch's existence from unauthorised callers.
            return response()->json(
                ['message' => __('chunky::chunky.http.batch_not_found')],
                Response::HTTP_NOT_FOUND,
            );
        }

        if (! $manager->cancelBatch($batchId)) {
            return response()->json(
                ['message' => __('chunky::chunky.http.batch_not_found')],
                Response::HTTP_CONFLICT,
            );
        }

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
