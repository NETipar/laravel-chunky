<?php

declare(strict_types=1);

namespace NETipar\Chunky\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use NETipar\Chunky\Domain\BatchStatus;

/**
 * @property string $batch_id
 * @property int $total_files
 * @property int $completed_files
 * @property int $failed_files
 * @property BatchStatus $status
 * @property string|null $profile
 * @property array<string, mixed>|null $metadata
 * @property string|null $user_id
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ChunkyBatch extends Model
{
    protected $table = 'chunky_batches';

    protected $primaryKey = 'batch_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_files' => 'integer',
            'completed_files' => 'integer',
            'failed_files' => 'integer',
            'metadata' => 'array',
            'status' => BatchStatus::class,
            'expires_at' => 'datetime',
        ];
    }
}
