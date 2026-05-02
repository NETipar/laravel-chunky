<?php

declare(strict_types=1);

namespace NETipar\Chunky\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use NETipar\Chunky\Enums\BatchStatus;
use NETipar\Chunky\Events\BatchCompleted;
use NETipar\Chunky\Events\BatchPartiallyCompleted;

/**
 * @property string $id
 * @property string $batch_id
 * @property ?string $user_id
 * @property int $total_files
 * @property int $completed_files
 * @property int $failed_files
 * @property ?string $context
 * @property ?array<string, mixed> $metadata
 * @property BatchStatus $status
 * @property ?CarbonInterface $completed_at
 * @property CarbonInterface $expires_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class ChunkyBatch extends Model
{
    use HasUlids;

    protected $table = 'chunky_batches';

    /** @var array<int, string> */
    protected $fillable = [
        'batch_id',
        'user_id',
        'total_files',
        'completed_files',
        'failed_files',
        'context',
        'metadata',
        'status',
        'completed_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'total_files' => 'integer',
            'completed_files' => 'integer',
            'failed_files' => 'integer',
            'metadata' => 'array',
            'status' => BatchStatus::class,
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<ChunkedUpload, $this>
     */
    public function uploads(): HasMany
    {
        return $this->hasMany(ChunkedUpload::class, 'batch_id', 'batch_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isFinished(): bool
    {
        return $this->completed_files + $this->failed_files >= $this->total_files;
    }

    public function markUploadCompleted(): void
    {
        $this->increment('completed_files');
        $this->refresh();
        $this->checkCompletion();
    }

    public function markUploadFailed(): void
    {
        $this->increment('failed_files');
        $this->refresh();
        $this->checkCompletion();
    }

    private function checkCompletion(): void
    {
        if (! $this->isFinished()) {
            if ($this->status === BatchStatus::Pending) {
                $this->update(['status' => BatchStatus::Processing]);
            }

            return;
        }

        $status = $this->failed_files > 0
            ? BatchStatus::PartiallyCompleted
            : BatchStatus::Completed;

        // CAS: only the first finisher flips a non-terminal status to a terminal
        // one. Without this two AssembleFileJob workers can both refresh() while
        // the row is in Processing, both decide they completed it, and both
        // dispatch a BatchCompleted broadcast — duplicate notifications on the
        // frontend.
        $updated = static::query()
            ->where('batch_id', $this->batch_id)
            ->whereNotIn('status', [BatchStatus::Completed, BatchStatus::PartiallyCompleted])
            ->update(['status' => $status, 'completed_at' => now()]);

        if ($updated === 0) {
            return;
        }

        $this->refresh();

        $userId = $this->user_id !== null ? (string) $this->user_id : null;

        match ($status) {
            BatchStatus::Completed => BatchCompleted::dispatch($this->batch_id, $this->total_files, $userId),
            BatchStatus::PartiallyCompleted => BatchPartiallyCompleted::dispatch(
                $this->batch_id, $this->completed_files, $this->failed_files, $this->total_files, $userId
            ),
            default => null,
        };
    }
}
