<?php

declare(strict_types=1);

namespace NETipar\Chunky\Tests\Doubles;

use NETipar\Chunky\Exceptions\ChunkyException;
use NETipar\Chunky\Ports\DirectUploadTransport;

/**
 * In-memory DirectUploadTransport for feature tests: presigns fake URLs,
 * tracks remote uploads, and lets tests script part listings and object sizes.
 */
final class InMemoryDirectTransport implements DirectUploadTransport
{
    /** @var array<string, string> remoteUploadId => key */
    public array $created = [];

    /** @var array<string, array<int, string>> remoteUploadId => [index => ETag] */
    public array $remoteParts = [];

    /** @var array<string, array<int, string>> remoteUploadId => parts passed to complete() */
    public array $completed = [];

    /** @var list<string> aborted remoteUploadIds */
    public array $aborted = [];

    /** Size reported for any completed object; set to the expected file size. */
    public ?int $objectSize = null;

    public int $listPartsCalls = 0;

    public bool $failComplete = false;

    private int $counter = 0;

    public function create(string $key, ?string $mimeType): string
    {
        $id = 'remote-'.(++$this->counter);
        $this->created[$id] = $key;

        return $id;
    }

    public function presignParts(string $key, string $remoteUploadId, array $indexes): array
    {
        $urls = [];

        foreach ($indexes as $index) {
            $partNumber = $index + 1;
            $urls[$index] = "https://s3.test/{$key}?partNumber={$partNumber}&uploadId={$remoteUploadId}&X-Amz-Signature=fake";
        }

        return $urls;
    }

    public function listParts(string $key, string $remoteUploadId): array
    {
        $this->listPartsCalls++;

        return $this->remoteParts[$remoteUploadId] ?? [];
    }

    public function complete(string $key, string $remoteUploadId, array $parts): void
    {
        if ($this->failComplete) {
            throw new ChunkyException('CompleteMultipartUpload failed (InvalidPart).');
        }

        $this->completed[$remoteUploadId] = $parts;
    }

    public function abort(string $key, string $remoteUploadId): void
    {
        $this->aborted[] = $remoteUploadId;
    }

    public function size(string $key): int
    {
        return $this->objectSize ?? 0;
    }
}
