<?php

declare(strict_types=1);

namespace NETipar\Chunky\Adapters\Filesystem;

use DateTimeImmutable;
use NETipar\Chunky\Domain\ChunkProgress;
use NETipar\Chunky\Domain\UploadRecord;
use NETipar\Chunky\Domain\UploadStatus;
use NETipar\Chunky\Exceptions\ChunkyException;
use NETipar\Chunky\Ports\LockProvider;
use NETipar\Chunky\Ports\UploadRepository;

final class FilesystemUploadRepository implements UploadRepository
{
    public function __construct(
        private readonly JsonFileStore $store,
        private readonly LockProvider $lock,
        private readonly string $directory = 'chunky/state/uploads',
    ) {}

    public function create(UploadRecord $record): void
    {
        $this->store->write($this->path($record->uploadId), $record->toArray());
    }

    public function find(string $uploadId): ?UploadRecord
    {
        $data = $this->store->read($this->path($uploadId));

        return $data === null ? null : UploadRecord::fromArray($data);
    }

    public function findByFingerprint(string $fingerprint, ?string $userId): ?UploadRecord
    {
        foreach ($this->allRecords() as $record) {
            if ($record->fingerprint === $fingerprint
                && $record->userId === $userId
                && ! $record->status->isTerminal()) {
                return $record;
            }
        }

        return null;
    }

    public function findByBatch(string $batchId): array
    {
        return array_values(array_filter(
            $this->allRecords(),
            static fn (UploadRecord $r): bool => $r->batchId === $batchId,
        ));
    }

    public function markChunk(string $uploadId, int $chunkIndex): ChunkProgress
    {
        return $this->lock->withLock($this->lockKey($uploadId), function () use ($uploadId, $chunkIndex): ChunkProgress {
            $record = $this->find($uploadId);

            if ($record === null) {
                throw new ChunkyException("Upload '{$uploadId}' not found.");
            }

            if (! in_array($chunkIndex, $record->uploadedChunks, true)) {
                $chunks = $record->uploadedChunks;
                $chunks[] = $chunkIndex;
                sort($chunks);
                $record = $record->with(uploadedChunks: $chunks);
                $this->store->write($this->path($uploadId), $record->toArray());
            }

            return new ChunkProgress(count($record->uploadedChunks), $record->totalChunks);
        });
    }

    public function transition(
        string $uploadId,
        UploadStatus $from,
        UploadStatus $to,
        array $attrs = [],
        array $guard = [],
    ): bool {
        return $this->lock->withLock($this->lockKey($uploadId), function () use ($uploadId, $from, $to, $attrs, $guard): bool {
            $record = $this->find($uploadId);

            if ($record === null || $record->status !== $from) {
                return false;
            }

            if (isset($guard['claimed_before'])) {
                $threshold = $guard['claimed_before'];

                if (! $threshold instanceof DateTimeImmutable
                    || $record->claimedAt === null
                    || $record->claimedAt >= $threshold) {
                    return false;
                }
            }

            $claimedAt = $attrs['claimed_at'] ?? null;
            /** @var array<string, mixed>|null $resultPayload */
            $resultPayload = isset($attrs['result_payload']) && is_array($attrs['result_payload'])
                ? $attrs['result_payload']
                : null;

            $record = $record->with(
                status: $to,
                finalPath: isset($attrs['final_path']) && is_string($attrs['final_path']) ? $attrs['final_path'] : null,
                claimedAt: $claimedAt instanceof DateTimeImmutable ? $claimedAt : null,
                resultPayload: $resultPayload,
            );

            $this->store->write($this->path($uploadId), $record->toArray());

            return true;
        });
    }

    public function findExpired(DateTimeImmutable $before, int $limit): array
    {
        $expired = [];

        foreach ($this->allRecords() as $record) {
            if (count($expired) >= $limit) {
                break;
            }

            if ($record->isExpiredAt($before) && ! $record->status->isTerminal()) {
                $expired[] = $record;
            }
        }

        return $expired;
    }

    public function delete(string $uploadId): void
    {
        $this->store->delete($this->path($uploadId));
    }

    /**
     * @return list<UploadRecord>
     */
    private function allRecords(): array
    {
        $records = [];

        foreach ($this->store->list($this->directory) as $path) {
            $data = $this->store->read($path);

            if ($data !== null) {
                $records[] = UploadRecord::fromArray($data);
            }
        }

        return $records;
    }

    private function path(string $uploadId): string
    {
        return $this->directory.'/'.$this->safeId($uploadId).'.json';
    }

    private function lockKey(string $uploadId): string
    {
        return 'chunky:upload:'.$uploadId;
    }

    private function safeId(string $uploadId): string
    {
        if ($uploadId === ''
            || str_contains($uploadId, '/')
            || str_contains($uploadId, '\\')
            || str_contains($uploadId, '..')
            || str_contains($uploadId, "\0")) {
            throw new ChunkyException("Unsafe upload id '{$uploadId}'.");
        }

        return $uploadId;
    }
}
