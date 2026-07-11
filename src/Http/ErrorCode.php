<?php

declare(strict_types=1);

namespace NETipar\Chunky\Http;

enum ErrorCode: string
{
    case ValidationFailed = 'validation_failed';
    case ProfileNotFound = 'profile_not_found';
    case Unauthorized = 'unauthorized';
    case UploadNotFound = 'upload_not_found';
    case BatchNotFound = 'batch_not_found';
    case UploadExpired = 'upload_expired';
    case InvalidState = 'invalid_state';
    case ChunkIndexOutOfRange = 'chunk_index_out_of_range';
    case ChecksumMismatch = 'checksum_mismatch';
    case LockTimeout = 'lock_timeout';
    case AssemblyFailed = 'assembly_failed';

    public function status(): int
    {
        return match ($this) {
            self::ValidationFailed,
            self::ProfileNotFound,
            self::ChunkIndexOutOfRange,
            self::ChecksumMismatch => 422,
            self::Unauthorized => 403,
            self::UploadNotFound, self::BatchNotFound => 404,
            self::InvalidState => 409,
            self::UploadExpired => 410,
            self::AssemblyFailed => 500,
            self::LockTimeout => 503,
        };
    }

    /**
     * A generic, non-leaking user-facing message. Internal exception messages
     * (which may contain paths/sizes) are never sent to the client.
     */
    public function message(): string
    {
        return match ($this) {
            self::ValidationFailed => 'The given data was invalid.',
            self::ProfileNotFound => 'The requested upload profile does not exist.',
            self::Unauthorized => 'This action is unauthorized.',
            self::UploadNotFound => 'Upload not found.',
            self::BatchNotFound => 'Batch not found.',
            self::UploadExpired => 'The upload has expired.',
            self::InvalidState => 'The upload is not in a state that allows this action.',
            self::ChunkIndexOutOfRange => 'The chunk index is out of range.',
            self::ChecksumMismatch => 'The chunk checksum did not match.',
            self::LockTimeout => 'The upload is busy, please retry.',
            self::AssemblyFailed => 'The file could not be assembled.',
        };
    }
}
