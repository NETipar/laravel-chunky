<?php

declare(strict_types=1);

namespace NETipar\Chunky\Domain;

use DateTimeImmutable;
use DateTimeInterface;
use NETipar\Chunky\Support\ChunkCalculator;
use NETipar\Chunky\Support\Coerce;

final readonly class UploadRecord
{
    /**
     * @param  list<int>  $uploadedChunks
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>|null  $resultPayload
     */
    public function __construct(
        public string $uploadId,
        public string $fileName,
        public int $fileSize,
        public ?string $mimeType,
        public int $chunkSize,
        public int $totalChunks,
        public string $disk,
        public ?string $profile = null,
        public array $metadata = [],
        public array $uploadedChunks = [],
        public UploadStatus $status = UploadStatus::Pending,
        public ?string $finalPath = null,
        public ?string $batchId = null,
        public ?string $userId = null,
        public ?string $fingerprint = null,
        public ?DateTimeImmutable $expiresAt = null,
        public ?DateTimeImmutable $claimedAt = null,
        public ?array $resultPayload = null,
    ) {}

    public function progress(): float
    {
        return ChunkCalculator::progress(count($this->uploadedChunks), $this->totalChunks);
    }

    public function isComplete(): bool
    {
        return $this->totalChunks > 0 && count($this->uploadedChunks) >= $this->totalChunks;
    }

    public function isExpiredAt(DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && $this->expiresAt < $now;
    }

    /**
     * Copy-on-write. A null argument keeps the existing value; these fields are
     * only ever set (never cleared) as an upload progresses, so null-as-"keep"
     * is unambiguous here.
     *
     * @param  list<int>|null  $uploadedChunks
     * @param  array<string, mixed>|null  $resultPayload
     */
    public function with(
        ?UploadStatus $status = null,
        ?string $finalPath = null,
        ?DateTimeImmutable $claimedAt = null,
        ?array $resultPayload = null,
        ?array $uploadedChunks = null,
    ): self {
        return new self(
            uploadId: $this->uploadId,
            fileName: $this->fileName,
            fileSize: $this->fileSize,
            mimeType: $this->mimeType,
            chunkSize: $this->chunkSize,
            totalChunks: $this->totalChunks,
            disk: $this->disk,
            profile: $this->profile,
            metadata: $this->metadata,
            uploadedChunks: $uploadedChunks ?? $this->uploadedChunks,
            status: $status ?? $this->status,
            finalPath: $finalPath ?? $this->finalPath,
            batchId: $this->batchId,
            userId: $this->userId,
            fingerprint: $this->fingerprint,
            expiresAt: $this->expiresAt,
            claimedAt: $claimedAt ?? $this->claimedAt,
            resultPayload: $resultPayload ?? $this->resultPayload,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'upload_id' => $this->uploadId,
            'file_name' => $this->fileName,
            'file_size' => $this->fileSize,
            'mime_type' => $this->mimeType,
            'chunk_size' => $this->chunkSize,
            'total_chunks' => $this->totalChunks,
            'disk' => $this->disk,
            'profile' => $this->profile,
            'metadata' => $this->metadata,
            'uploaded_chunks' => $this->uploadedChunks,
            'status' => $this->status->value,
            'final_path' => $this->finalPath,
            'batch_id' => $this->batchId,
            'user_id' => $this->userId,
            'fingerprint' => $this->fingerprint,
            'expires_at' => $this->expiresAt?->format(DateTimeInterface::ATOM),
            'claimed_at' => $this->claimedAt?->format(DateTimeInterface::ATOM),
            'result_payload' => $this->resultPayload,
        ];
    }

    /**
     * The safe projection used for status responses and broadcasts. It never
     * exposes the storage disk, the absolute final path, the owning user, or
     * internal lifecycle bookkeeping.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        $data = $this->toArray();

        unset(
            $data['disk'],
            $data['final_path'],
            $data['user_id'],
            $data['claimed_at'],
            $data['expires_at'],
            $data['result_payload'],
            $data['fingerprint'],
        );

        $data['progress'] = $this->progress();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            uploadId: self::string($data, 'upload_id'),
            fileName: self::string($data, 'file_name'),
            fileSize: self::int($data, 'file_size'),
            mimeType: self::nullableString($data, 'mime_type'),
            chunkSize: self::int($data, 'chunk_size'),
            totalChunks: self::int($data, 'total_chunks'),
            disk: self::string($data, 'disk'),
            profile: self::nullableString($data, 'profile'),
            metadata: self::stringKeyedArray($data['metadata'] ?? []),
            uploadedChunks: self::intList($data['uploaded_chunks'] ?? []),
            status: UploadStatus::tryFrom(Coerce::toString($data['status'] ?? 'pending')) ?? UploadStatus::Pending,
            finalPath: self::nullableString($data, 'final_path'),
            batchId: self::nullableString($data, 'batch_id'),
            userId: self::nullableString($data, 'user_id'),
            fingerprint: self::nullableString($data, 'fingerprint'),
            expiresAt: self::dateTime($data['expires_at'] ?? null),
            claimedAt: self::dateTime($data['claimed_at'] ?? null),
            resultPayload: isset($data['result_payload']) && is_array($data['result_payload'])
                ? self::stringKeyedArray($data['result_payload'])
                : null,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function string(array $data, string $key): string
    {
        return Coerce::toString($data[$key] ?? null);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function nullableString(array $data, string $key): ?string
    {
        return Coerce::toNullableString($data[$key] ?? null);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function int(array $data, string $key): int
    {
        return Coerce::toInt($data[$key] ?? null);
    }

    /**
     * @return list<int>
     */
    private static function intList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $v): int => Coerce::toInt($v), $value));
    }

    /**
     * @return array<string, mixed>
     */
    private static function stringKeyedArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        /** @var array<string, mixed> $normalized */
        $normalized = [];
        foreach ($value as $k => $v) {
            $normalized[(string) $k] = $v;
        }

        return $normalized;
    }

    private static function dateTime(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            return new DateTimeImmutable($value);
        }

        return null;
    }
}
