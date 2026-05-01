<?php

declare(strict_types=1);

namespace NETipar\Chunky\Authorization;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use NETipar\Chunky\Data\BatchMetadata;
use NETipar\Chunky\Data\UploadMetadata;

/**
 * Default authorisation policy:
 *
 *  1. If the host application has registered a Gate ability matching
 *     the action (`viewChunkyUpload`, `cancelChunkyUpload`,
 *     `viewChunkyBatch`, `cancelChunkyBatch`), defer to it. This is the
 *     escape hatch for admin/team rules without subclassing the
 *     authorizer — register a Gate in `AuthServiceProvider` and the
 *     package picks it up automatically.
 *
 *  2. Otherwise fall back to plain ownership: the authenticated user's
 *     id must equal the stored `userId`. String comparison so any
 *     user-id shape (int, UUID, ULID) works.
 *
 *  3. Anonymous uploads (no `userId` recorded) are accessible to anyone
 *     by default. Set `chunky.authorization.allow_anonymous = false`
 *     to require an authenticated user even when no owner was recorded
 *     — opt-in, since flipping it on existing setups breaks any flow
 *     that historically allowed unauthenticated reads.
 */
class DefaultAuthorizer implements Authorizer
{
    public function canAccessUpload(?Authenticatable $user, UploadMetadata $upload): bool
    {
        if (Gate::has('viewChunkyUpload')) {
            return Gate::forUser($user)->allows('viewChunkyUpload', $upload);
        }

        return $this->matchesOwner($user, $upload->userId);
    }

    public function canCancelUpload(?Authenticatable $user, UploadMetadata $upload): bool
    {
        if (Gate::has('cancelChunkyUpload')) {
            return Gate::forUser($user)->allows('cancelChunkyUpload', $upload);
        }

        return $this->canAccessUpload($user, $upload);
    }

    public function canAccessBatch(?Authenticatable $user, BatchMetadata $batch): bool
    {
        if (Gate::has('viewChunkyBatch')) {
            return Gate::forUser($user)->allows('viewChunkyBatch', $batch);
        }

        return $this->matchesOwner($user, $batch->userId);
    }

    public function canCancelBatch(?Authenticatable $user, BatchMetadata $batch): bool
    {
        if (Gate::has('cancelChunkyBatch')) {
            return Gate::forUser($user)->allows('cancelChunkyBatch', $batch);
        }

        return $this->canAccessBatch($user, $batch);
    }

    private function matchesOwner(?Authenticatable $user, ?string $ownerId): bool
    {
        if ($ownerId === null) {
            // Anonymous upload (no owner recorded). Allow by default to
            // preserve back-compat; opt out with allow_anonymous=false.
            return (bool) config('chunky.authorization.allow_anonymous', true);
        }

        if ($user === null) {
            return false;
        }

        $authId = $user->getAuthIdentifier();

        // String comparison so any user-id shape (int, UUID, ULID) works
        // out of the box without configuration.
        return $authId !== null && (string) $authId === $ownerId;
    }
}
