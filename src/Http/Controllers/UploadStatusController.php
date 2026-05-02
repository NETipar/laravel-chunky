<?php

declare(strict_types=1);

namespace NETipar\Chunky\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use NETipar\Chunky\Authorization\Authorizer;
use NETipar\Chunky\ChunkyManager;

class UploadStatusController extends Controller
{
    public function __invoke(
        Request $request,
        string $uploadId,
        ChunkyManager $manager,
        Authorizer $authorizer,
    ): JsonResponse {
        $status = $manager->status($uploadId);

        if (! $status) {
            return response()->json(['message' => __('chunky::chunky.http.upload_not_found')], 404);
        }

        // Use $request->user() (DI) instead of the auth() facade so the
        // controller is mockable from a unit test without booting the
        // auth manager.
        if (! $authorizer->canAccessUpload($request->user(), $status)) {
            // Match the not-found response so non-owners can't probe which
            // upload IDs exist.
            return response()->json(['message' => __('chunky::chunky.http.upload_not_found')], 404);
        }

        return response()->json($status->toPublicArray());
    }
}
