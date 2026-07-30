<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use NETipar\Chunky\Domain\UploadRecord;
use NETipar\Chunky\Exceptions\ChunkIntegrityException;
use NETipar\Chunky\Services\Assembly\AssemblyState;
use NETipar\Chunky\Services\Assembly\Steps\IntegrityStep;

beforeEach(fn () => Storage::fake('local'));

function integrityState(string $content, ?string $reported, ?string $computed): AssemblyState
{
    $record = new UploadRecord(
        uploadId: 'up-1',
        fileName: 'file.bin',
        fileSize: strlen($content),
        mimeType: null,
        chunkSize: 8,
        totalChunks: 1,
        disk: 'local',
        fileChecksum: $reported,
    );

    Storage::disk('local')->put('chunky/staging/up-1.part', $content);

    $state = new AssemblyState($record, null, 'local');
    $state->stagingPath = 'chunky/staging/up-1.part';
    $state->computedChecksum = $computed;

    return $state;
}

it('passes when the reported checksum matches the computed one', function () {
    $hash = hash('sha256', 'HELLO');
    $step = new IntegrityStep(Storage::disk('local'));

    $step->execute(integrityState('HELLO', $hash, $hash));

    expect(true)->toBeTrue();
});

it('accepts an uppercase reported checksum', function () {
    $hash = hash('sha256', 'HELLO');
    $step = new IntegrityStep(Storage::disk('local'));

    $step->execute(integrityState('HELLO', strtoupper($hash), $hash));

    expect(true)->toBeTrue();
});

it('throws ChunkIntegrityException on a mismatch', function () {
    $step = new IntegrityStep(Storage::disk('local'));

    expect(fn () => $step->execute(integrityState('HELLO', str_repeat('0', 64), hash('sha256', 'HELLO'))))
        ->toThrow(ChunkIntegrityException::class);
});

it('skips the comparison when no checksum was reported', function () {
    $step = new IntegrityStep(Storage::disk('local'));

    $step->execute(integrityState('HELLO', null, hash('sha256', 'HELLO')));

    expect(true)->toBeTrue();
});
