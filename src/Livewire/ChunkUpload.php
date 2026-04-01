<?php

namespace NETipar\Chunky\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;
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

        $this->isComplete = true;
        $this->fileName = $uploadMetadata->fileName;
        $this->fileSize = $uploadMetadata->fileSize;

        $this->dispatch('chunky-upload-completed', [
            'uploadId' => $this->uploadId,
            'fileName' => $uploadMetadata->fileName,
            'fileSize' => $uploadMetadata->fileSize,
            'finalPath' => $uploadMetadata->finalPath,
            'disk' => $uploadMetadata->disk,
        ]);

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
