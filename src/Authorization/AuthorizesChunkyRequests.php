<?php

declare(strict_types=1);

namespace NETipar\Chunky\Authorization;

use NETipar\Chunky\ChunkyManager;

/**
 * Form-request-side helper for ownership checks. Looks up the upload or
 * batch via the route parameter, then defers to the configured Authorizer.
 *
 * Returning false from FormRequest::authorize() makes Laravel respond with
 * 403 automatically.
 */
trait AuthorizesChunkyRequests
{
    protected function userOwnsUpload(string $routeKey = 'uploadId'): bool
    {
        /** @var string|null $uploadId */
        $uploadId = $this->route($routeKey);

        if (! $uploadId) {
            return false;
        }

        $upload = app(ChunkyManager::class)->status($uploadId);

        if (! $upload) {
            // Surface as 403 rather than 404 so we don't reveal which upload
            // IDs exist to non-owners. The controller still handles the not
            // found case for the owner via the regular 404 response path.
            return false;
        }

        return app(Authorizer::class)->canAccessUpload($this->user(), $upload);
    }

    protected function userOwnsBatch(string $routeKey = 'batchId'): bool
    {
        /** @var string|null $batchId */
        $batchId = $this->route($routeKey);

        if (! $batchId) {
            return false;
        }

        $batch = app(ChunkyManager::class)->getBatchStatus($batchId);

        if (! $batch) {
            return false;
        }

        return app(Authorizer::class)->canAccessBatch($this->user(), $batch);
    }
}
