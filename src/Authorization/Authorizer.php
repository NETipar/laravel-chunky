<?php

declare(strict_types=1);

namespace NETipar\Chunky\Authorization;

use Illuminate\Contracts\Auth\Authenticatable;
use NETipar\Chunky\Domain\BatchRecord;
use NETipar\Chunky\Domain\UploadRecord;

interface Authorizer
{
    public function owns(?Authenticatable $user, UploadRecord $upload): bool;

    public function ownsBatch(?Authenticatable $user, BatchRecord $batch): bool;
}
