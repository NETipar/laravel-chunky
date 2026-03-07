<?php

namespace NETipar\Chunky\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use NETipar\Chunky\Exceptions\ChunkIntegrityException;
use Symfony\Component\HttpFoundation\Response;

class VerifyChunkIntegrity
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('chunky.verify_integrity', true)) {
            return $next($request);
        }

        $checksum = $request->input('checksum');
        $chunk = $request->file('chunk');

        if (! $checksum || ! $chunk) {
            return $next($request);
        }

        $actualChecksum = hash('sha256', $chunk->getContent());

        if (! hash_equals($checksum, $actualChecksum)) {
            throw ChunkIntegrityException::checksumMismatch(
                $request->route('uploadId', ''),
                (int) $request->input('chunk_index', 0),
            );
        }

        return $next($request);
    }
}
