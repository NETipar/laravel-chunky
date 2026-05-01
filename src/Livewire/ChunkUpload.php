<?php

declare(strict_types=1);

namespace NETipar\Chunky\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use NETipar\Chunky\Authorization\Authorizer;
use NETipar\Chunky\ChunkyManager;
use NETipar\Chunky\Enums\UploadStatus;

class ChunkUpload extends Component
{
    public ?string $context = null;

    public ?string $uploadId = null;

    public bool $isComplete = false;

    public ?string $error = null;

    public ?string $fileName = null;

    public int $fileSize = 0;

    /**
     * @var array<string, mixed>
     */
    public array $metadata = [];

    /**
     * @return array<string, mixed>
     */
    public function completeUpload(): array
    {
        if (! $this->uploadId) {
            $this->error = 'No upload in progress.';

            return ['success' => false, 'error' => $this->error];
        }

        $manager = app(ChunkyManager::class);
        $uploadMetadata = $manager->status($this->uploadId);

        if (! $uploadMetadata || $uploadMetadata->status !== UploadStatus::Completed) {
            $this->error = 'Upload not yet completed.';

            return ['success' => false, 'error' => $this->error];
        }

        // Ownership check: the public uploadId property is round-tripped
        // through the wire payload, so a malicious client could swap it
        // mid-session. Defer to the bound Authorizer for the same rules
        // the HTTP and broadcast layers use.
        if (! app(Authorizer::class)->canAccessUpload(auth()->user(), $uploadMetadata)) {
            $this->error = 'Upload not yet completed.';
            $this->uploadId = null;

            return ['success' => false, 'error' => $this->error];
        }

        $this->isComplete = true;
        $this->fileName = $uploadMetadata->fileName;
        $this->fileSize = $uploadMetadata->fileSize;

        // Mirror the broadcast payload sanitisation: by default we don't
        // emit the storage disk or the absolute final_path to the
        // browser. Set chunky.broadcasting.expose_internal_paths = true
        // to opt back in.
        $payload = [
            'uploadId' => $this->uploadId,
            'fileName' => $uploadMetadata->fileName,
            'fileSize' => $uploadMetadata->fileSize,
        ];

        if (config('chunky.broadcasting.expose_internal_paths', false)) {
            $payload['finalPath'] = $uploadMetadata->finalPath;
            $payload['disk'] = $uploadMetadata->disk;
        }

        $this->dispatch('chunky-upload-completed', $payload);

        return ['success' => true, 'uploadId' => $this->uploadId];
    }

    public function resetUpload(): void
    {
        $this->uploadId = null;
        $this->isComplete = false;
        $this->error = null;
        $this->fileName = null;
        $this->fileSize = 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAlpineOptionsProperty(): array
    {
        return array_filter([
            'context' => $this->context,
        ]);
    }

    public function render(): View
    {
        return view('chunky::livewire.chunk-upload');
    }
}
