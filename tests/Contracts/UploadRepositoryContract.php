<?php

declare(strict_types=1);

use NETipar\Chunky\Domain\UploadRecord;
use NETipar\Chunky\Domain\UploadStatus;
use NETipar\Chunky\Exceptions\ChunkyException;
use NETipar\Chunky\Ports\UploadRepository;

if (! function_exists('contractUploadRecord')) {
    /**
     * @param  list<int>  $uploadedChunks
     */
    function contractUploadRecord(
        string $id,
        int $totalChunks = 10,
        UploadStatus $status = UploadStatus::Pending,
        ?string $batchId = null,
        ?string $userId = null,
        ?string $fingerprint = null,
        ?DateTimeImmutable $expiresAt = null,
        array $uploadedChunks = [],
    ): UploadRecord {
        return new UploadRecord(
            uploadId: $id,
            fileName: 'file.bin',
            fileSize: 1000,
            mimeType: 'application/octet-stream',
            chunkSize: 100,
            totalChunks: $totalChunks,
            disk: 'local',
            uploadedChunks: $uploadedChunks,
            status: $status,
            batchId: $batchId,
            userId: $userId,
            fingerprint: $fingerprint,
            expiresAt: $expiresAt,
        );
    }
}

/**
 * The behavioural contract every UploadRepository adapter must satisfy. Called
 * once per adapter with a factory that returns a fresh, empty repository.
 *
 * @param  Closure(): UploadRepository  $makeRepository
 */
function uploadRepositoryContract(Closure $makeRepository): void
{
    it('creates and finds a record', function () use ($makeRepository) {
        $repo = $makeRepository();
        $repo->create(contractUploadRecord('up-1'));

        $found = $repo->find('up-1');

        expect($found)->not->toBeNull();
        expect($found->uploadId)->toBe('up-1');
        expect($found->status)->toBe(UploadStatus::Pending);
    });

    it('returns null for a missing record', function () use ($makeRepository) {
        expect($makeRepository()->find('nope'))->toBeNull();
    });

    it('marks chunks and returns ordered, de-duplicated progress', function () use ($makeRepository) {
        $repo = $makeRepository();
        $repo->create(contractUploadRecord('up-1', totalChunks: 3));

        $repo->markChunk('up-1', 0);
        $repo->markChunk('up-1', 2);
        $progress = $repo->markChunk('up-1', 1);

        expect($repo->find('up-1')->uploadedChunks)->toBe([0, 1, 2]);
        expect($progress->uploadedCount)->toBe(3);
        expect($progress->isComplete())->toBeTrue();
    });

    it('does not duplicate a chunk index', function () use ($makeRepository) {
        $repo = $makeRepository();
        $repo->create(contractUploadRecord('up-1', totalChunks: 3));

        $repo->markChunk('up-1', 0);
        $progress = $repo->markChunk('up-1', 0);

        expect($repo->find('up-1')->uploadedChunks)->toBe([0]);
        expect($progress->uploadedCount)->toBe(1);
        expect($progress->isComplete())->toBeFalse();
    });

    it('throws when marking a chunk on a missing upload', function () use ($makeRepository) {
        expect(fn () => $makeRepository()->markChunk('missing', 0))
            ->toThrow(ChunkyException::class);
    });

    it('persists the whole-file checksum with first-write-wins semantics', function () use ($makeRepository) {
        $repo = $makeRepository();
        $repo->create(contractUploadRecord('up-1'));

        $first = str_repeat('a', 64);
        $repo->setFileChecksum('up-1', $first);
        $repo->setFileChecksum('up-1', str_repeat('b', 64));

        expect($repo->find('up-1')->fileChecksum)->toBe($first);
    });

    it('ignores a checksum write for a missing upload', function () use ($makeRepository) {
        $repo = $makeRepository();

        $repo->setFileChecksum('missing', str_repeat('a', 64));

        expect($repo->find('missing'))->toBeNull();
    });

    it('transitions atomically only from the expected status', function () use ($makeRepository) {
        $repo = $makeRepository();
        $repo->create(contractUploadRecord('up-1', status: UploadStatus::Pending));

        expect($repo->transition('up-1', UploadStatus::Pending, UploadStatus::Uploading))->toBeTrue();
        expect($repo->find('up-1')->status)->toBe(UploadStatus::Uploading);

        // Wrong `from` now that the record has moved on.
        expect($repo->transition('up-1', UploadStatus::Pending, UploadStatus::Cancelled))->toBeFalse();
        expect($repo->find('up-1')->status)->toBe(UploadStatus::Uploading);
    });

    it('returns false transitioning a missing record', function () use ($makeRepository) {
        expect($makeRepository()->transition('nope', UploadStatus::Pending, UploadStatus::Uploading))->toBeFalse();
    });

    it('writes attributes together with the transition', function () use ($makeRepository) {
        $repo = $makeRepository();
        $repo->create(contractUploadRecord('up-1', status: UploadStatus::Assembling));

        $ok = $repo->transition('up-1', UploadStatus::Assembling, UploadStatus::Completed, [
            'final_path' => 'chunky/uploads/up-1/file.bin',
            'result_payload' => ['media_id' => 7],
        ]);

        expect($ok)->toBeTrue();
        $record = $repo->find('up-1');
        expect($record->status)->toBe(UploadStatus::Completed);
        expect($record->finalPath)->toBe('chunky/uploads/up-1/file.bin');
        expect($record->resultPayload)->toBe(['media_id' => 7]);
    });

    it('rejects a stale-claim guard while the claim is fresh', function () use ($makeRepository) {
        $repo = $makeRepository();
        $repo->create(contractUploadRecord('up-1', status: UploadStatus::Uploading));

        $claimedAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        expect($repo->transition('up-1', UploadStatus::Uploading, UploadStatus::Assembling, [
            'claimed_at' => $claimedAt,
        ]))->toBeTrue();

        // A second worker with a stale threshold AT the claim instant must fail:
        // the claim is not older than the threshold.
        expect($repo->transition('up-1', UploadStatus::Assembling, UploadStatus::Assembling, [
            'claimed_at' => new DateTimeImmutable('2026-01-01T00:00:05+00:00'),
        ], [
            'claimed_before' => $claimedAt,
        ]))->toBeFalse();
    });

    it('takes over a stale claim past the threshold', function () use ($makeRepository) {
        $repo = $makeRepository();
        $repo->create(contractUploadRecord('up-1', status: UploadStatus::Uploading));

        $oldClaim = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $repo->transition('up-1', UploadStatus::Uploading, UploadStatus::Assembling, ['claimed_at' => $oldClaim]);

        $newClaim = new DateTimeImmutable('2026-01-01T00:20:00+00:00');
        $threshold = new DateTimeImmutable('2026-01-01T00:15:00+00:00');

        expect($repo->transition('up-1', UploadStatus::Assembling, UploadStatus::Assembling, [
            'claimed_at' => $newClaim,
        ], [
            'claimed_before' => $threshold,
        ]))->toBeTrue();

        expect($repo->find('up-1')->claimedAt?->format('c'))->toBe($newClaim->format('c'));
    });

    it('finds a live upload by fingerprint scoped to the user', function () use ($makeRepository) {
        $repo = $makeRepository();
        $repo->create(contractUploadRecord('up-1', userId: '7', fingerprint: 'fp-abc'));

        expect($repo->findByFingerprint('fp-abc', '7')?->uploadId)->toBe('up-1');
        // Different user must not match (no cross-user resume).
        expect($repo->findByFingerprint('fp-abc', '8'))->toBeNull();
        // Unknown fingerprint.
        expect($repo->findByFingerprint('fp-other', '7'))->toBeNull();
    });

    it('does not resume a terminal upload by fingerprint', function () use ($makeRepository) {
        $repo = $makeRepository();
        $repo->create(contractUploadRecord('up-1', status: UploadStatus::Completed, userId: '7', fingerprint: 'fp-abc'));

        expect($repo->findByFingerprint('fp-abc', '7'))->toBeNull();
    });

    it('finds all members of a batch', function () use ($makeRepository) {
        $repo = $makeRepository();
        $repo->create(contractUploadRecord('up-1', batchId: 'b-1'));
        $repo->create(contractUploadRecord('up-2', batchId: 'b-1'));
        $repo->create(contractUploadRecord('up-3', batchId: 'b-2'));

        $ids = array_map(static fn (UploadRecord $r): string => $r->uploadId, $repo->findByBatch('b-1'));
        sort($ids);

        expect($ids)->toBe(['up-1', 'up-2']);
    });

    it('finds expired non-terminal records, skipping fresh and terminal ones, respecting the limit', function () use ($makeRepository) {
        $repo = $makeRepository();
        $past = new DateTimeImmutable('2020-01-01T00:00:00+00:00');
        $future = new DateTimeImmutable('2999-01-01T00:00:00+00:00');
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        $repo->create(contractUploadRecord('stale-1', expiresAt: $past));
        $repo->create(contractUploadRecord('stale-2', expiresAt: $past));
        $repo->create(contractUploadRecord('fresh', expiresAt: $future));
        $repo->create(contractUploadRecord('done', status: UploadStatus::Completed, expiresAt: $past));

        $expired = $repo->findExpired($now, 10);
        $ids = array_map(static fn (UploadRecord $r): string => $r->uploadId, $expired);
        sort($ids);

        expect($ids)->toBe(['stale-1', 'stale-2']);
        expect($repo->findExpired($now, 1))->toHaveCount(1);
    });

    it('deletes a record', function () use ($makeRepository) {
        $repo = $makeRepository();
        $repo->create(contractUploadRecord('up-1'));

        $repo->delete('up-1');

        expect($repo->find('up-1'))->toBeNull();
    });
}
