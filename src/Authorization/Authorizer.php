<?php

declare(strict_types=1);

namespace NETipar\Chunky\Authorization;

use Illuminate\Contracts\Auth\Authenticatable;
use NETipar\Chunky\Data\BatchMetadata;
use NETipar\Chunky\Data\UploadMetadata;

/**
 * Decides whether the current authenticated user (if any) is allowed to
 * access or mutate a given upload or batch. Bind a custom implementation in
 * the service container to plug in domain-specific rules (admin overrides,
 * shared batches, etc.).
 */
interface Authorizer
{
    public function canAccessUpload(?Authenticatable $user, UploadMetadata $upload): bool;

    public function canAccessBatch(?Authenticatable $user, BatchMetadata $batch): bool;
}
