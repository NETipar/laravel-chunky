<?php

declare(strict_types=1);

namespace NETipar\Chunky\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use NETipar\Chunky\Config\ChunkyConfig;
use NETipar\Chunky\Exceptions\ChunkIntegrityException;
use NETipar\Chunky\Support\Coerce;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies the optional per-chunk checksum. When integrity.required is on, the
 * checksum is mandatory; otherwise it is verified only when supplied.
 */
final class VerifyChunkIntegrity
{
    public function __construct(
        private readonly ChunkyConfig $config,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $uploadId = Coerce::toString($request->route('uploadId'));
        $chunkIndex = Coerce::toInt($request->input('chunk_index'));
        $checksum = Coerce::toNullableString($request->input('checksum'));

        if ($checksum === null) {
            if ($this->config->integrityRequired) {
                throw ChunkIntegrityException::checksumMismatch($uploadId, $chunkIndex);
            }

            return $next($request);
        }

        $file = $request->file('chunk');

        if (! $file instanceof UploadedFile) {
            // No file to verify against; validation will reject the missing chunk.
            return $next($request);
        }

        $path = $file->getRealPath();
        $actual = $path === false ? false : hash_file($this->config->integrityAlgorithm, $path);

        if ($actual === false || ! hash_equals(strtolower($checksum), strtolower($actual))) {
            throw ChunkIntegrityException::checksumMismatch($uploadId, $chunkIndex);
        }

        return $next($request);
    }
}
