<?php

declare(strict_types=1);

namespace NETipar\Chunky\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use NETipar\Chunky\Enums\UploadStatus;

class ChunkedUpload extends Model
{
    use HasUlids;

    /** @var array<int, string> */
    protected $fillable = [
        'upload_id',
        'batch_id',
        'user_id',
        'file_name',
        'file_size',
        'mime_type',
        'chunk_size',
        'total_chunks',
        'uploaded_chunks',
        'disk',
        'context',
        'final_path',
        'metadata',
        'status',
        'completed_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'chunk_size' => 'integer',
            'total_chunks' => 'integer',
            'uploaded_chunks' => 'array',
            'metadata' => 'array',
            'status' => UploadStatus::class,
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ChunkyBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ChunkyBatch::class, 'batch_id', 'batch_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isComplete(): bool
    {
        return count($this->uploaded_chunks ?? []) >= $this->total_chunks;
    }

    public function markChunkUploaded(int $chunkIndex): void
    {
        $chunks = $this->uploaded_chunks ?? [];

        if (! in_array($chunkIndex, $chunks)) {
            $chunks[] = $chunkIndex;
            sort($chunks);
        }

        $this->update(['uploaded_chunks' => $chunks]);
    }
}
