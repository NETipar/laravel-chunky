<?php

namespace NETipar\Chunky\Authorization;

use Illuminate\Contracts\Auth\Authenticatable;
use NETipar\Chunky\Data\BatchMetadata;
use NETipar\Chunky\Data\UploadMetadata;

/**
 * Default ownership rules:
 *
 *  - If the upload/batch carries no `userId` (anonymous upload, no auth
 *    middleware on the routes), access is allowed regardless of `$user`.
 *    This preserves backward compatibility with existing setups that never
 *    associated user IDs.
 *  - Otherwise the authenticated user's id must match the stored `userId`.
 *
 * If you want stricter behaviour (e.g. always require auth, or expand to
 * teams/admins), bind a custom Authorizer in the service container.
 */
class DefaultAuthorizer implements Authorizer
{
    public function canAccessUpload(?Authenticatable $user, UploadMetadata $upload): bool
    {
        return $this->matchesOwner($user, $upload->userId);
    }

    public function canAccessBatch(?Authenticatable $user, BatchMetadata $batch): bool
    {
        return $this->matchesOwner($user, $batch->userId);
    }

    private function matchesOwner(?Authenticatable $user, ?int $ownerId): bool
    {
        if ($ownerId === null) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        $authId = $user->getAuthIdentifier();

        return $authId !== null && (int) $authId === $ownerId;
    }
}
