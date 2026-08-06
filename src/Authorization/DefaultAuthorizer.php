<?php

declare(strict_types=1);

namespace NETipar\Chunky\Authorization;

use Illuminate\Contracts\Auth\Authenticatable;
use NETipar\Chunky\Domain\BatchRecord;
use NETipar\Chunky\Domain\UploadRecord;
use NETipar\Chunky\Support\Coerce;

/**
 * Ownership by user id. Anonymous resources (no owner recorded) stay open for
 * backward compatibility; owned resources require the same authenticated user.
 */
final class DefaultAuthorizer implements Authorizer
{
    public function owns(?Authenticatable $user, UploadRecord $upload): bool
    {
        return $this->matches($user, $upload->userId);
    }

    public function ownsBatch(?Authenticatable $user, BatchRecord $batch): bool
    {
        return $this->matches($user, $batch->userId);
    }

    private function matches(?Authenticatable $user, ?string $ownerId): bool
    {
        if ($ownerId === null) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        return Coerce::toNullableString($user->getAuthIdentifier()) === $ownerId;
    }
}
