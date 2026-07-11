<?php

declare(strict_types=1);

namespace NETipar\Chunky\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use NETipar\Chunky\Domain\UploadStatus;

/**
 * @property string $upload_id
 * @property string $file_name
 * @property int $file_size
 * @property string|null $mime_type
 * @property int $chunk_size
 * @property int $total_chunks
 * @property string $disk
 * @property string|null $profile
 * @property array<string, mixed>|null $metadata
 * @property list<int>|null $uploaded_chunks
 * @property UploadStatus $status
 * @property string|null $final_path
 * @property string|null $batch_id
 * @property string|null $user_id
 * @property string|null $fingerprint
 * @property Carbon|null $expires_at
 * @property Carbon|null $claimed_at
 * @property array<string, mixed>|null $result_payload
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ChunkedUpload extends Model
{
    protected $table = 'chunky_uploads';

    protected $primaryKey = 'upload_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'chunk_size' => 'integer',
            'total_chunks' => 'integer',
            'metadata' => 'array',
            'uploaded_chunks' => 'array',
            'status' => UploadStatus::class,
            'expires_at' => 'datetime',
            'claimed_at' => 'datetime',
            'result_payload' => 'array',
        ];
    }
}
