<?php

declare(strict_types=1);

namespace NETipar\Chunky\Ports;

/**
 * Remote multipart upload orchestration for the direct_s3 transport. Chunk
 * indexes are 0-based on the wire and mapped to 1-based part numbers by the
 * adapter. All methods may throw on transport errors; callers decide whether
 * a failure is fatal (complete) or best-effort (abort).
 */
interface DirectUploadTransport
{
    /**
     * Start a remote multipart upload for the given object key and return the
     * remote upload id.
     */
    public function create(string $key, ?string $mimeType): string;

    /**
     * Presigned PUT URLs for the given chunk indexes.
     *
     * @param  list<int>  $indexes
     * @return array<int, string> index => URL
     */
    public function presignParts(string $key, string $remoteUploadId, array $indexes): array;

    /**
     * Already-uploaded parts of the remote upload.
     *
     * @return array<int, string> index => ETag
     */
    public function listParts(string $key, string $remoteUploadId): array;

    /**
     * Complete the remote multipart upload from the client-collected ETags.
     *
     * @param  array<int, string>  $parts  index => ETag
     */
    public function complete(string $key, string $remoteUploadId, array $parts): void;

    /**
     * Abort the remote multipart upload, discarding uploaded parts.
     */
    public function abort(string $key, string $remoteUploadId): void;

    /**
     * Size of the completed remote object in bytes.
     */
    public function size(string $key): int;
}
