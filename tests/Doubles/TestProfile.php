<?php

declare(strict_types=1);

namespace NETipar\Chunky\Tests\Doubles;

use NETipar\Chunky\Profiles\CompletedUpload;
use NETipar\Chunky\Profiles\UploadContext;
use NETipar\Chunky\Profiles\UploadProfile;

/**
 * A profile that records assembly and returns a payload — exercises the
 * directory()/completed() hooks end to end.
 */
final class TestProfile extends UploadProfile
{
    public function directory(UploadContext $context): string
    {
        return 'test-uploads';
    }

    public function fileName(UploadContext $context, string $originalName): string
    {
        return 'assembled-'.$originalName;
    }

    /**
     * @return array<string, mixed>
     */
    public function completed(CompletedUpload $upload): array
    {
        return ['media_id' => 123, 'path' => $upload->path];
    }
}
