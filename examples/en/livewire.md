# Livewire Integration

Chunky includes a built-in Livewire component that uses Alpine.js under the hood. No npm package is needed -- everything is included in the Composer package.

## Requirements

- Livewire 3+
- Alpine.js (included with Livewire)

## Basic Usage

```blade
<livewire:chunky-upload />
```

This renders a complete upload UI with:
- Drag & drop / click-to-select dropzone
- Progress bar with percentage
- Pause / Resume / Cancel controls
- Error display with retry button
- Completion message

## With Context

Apply server-side validation rules:

```blade
<livewire:chunky-upload context="profile_avatar" />
```

## Custom UI via Slot

Override the default UI while keeping the upload logic:

```blade
<livewire:chunky-upload context="documents">
    <div
        x-data="chunkUpload({ context: 'documents' })"
        class="border-2 border-dashed rounded-lg p-8"
    >
        <div x-show="!isUploading && !isComplete">
            <label class="cursor-pointer">
                <input type="file" class="hidden" x-on:change="handleFileInput($event)" />
                <span>Click or drag to upload a document</span>
            </label>
        </div>

        <div x-show="isUploading" class="space-y-2">
            <div class="flex justify-between text-sm">
                <span x-text="currentFile?.name"></span>
                <span x-text="Math.round(progress) + '%'"></span>
            </div>
            <div class="h-2 bg-gray-200 rounded">
                <div class="h-2 bg-blue-500 rounded" :style="'width: ' + progress + '%'"></div>
            </div>
            <div class="flex gap-2">
                <button x-on:click="isPaused ? resume() : pause()" x-text="isPaused ? 'Resume' : 'Pause'"></button>
                <button x-on:click="cancel()">Cancel</button>
            </div>
        </div>

        <div x-show="isComplete" class="text-green-600">Upload complete!</div>

        <div x-show="error" class="text-red-600">
            <span x-text="error"></span>
            <button x-on:click="retry()">Retry</button>
        </div>
    </div>
</livewire:chunky-upload>
```

## Handling Upload Completion

### In the Parent Livewire Component

Listen for the `chunky-upload-completed` event:

```php
namespace App\Livewire;

use Livewire\Component;
use Livewire\Attributes\On;

class DocumentManager extends Component
{
    public array $uploads = [];

    #[On('chunky-upload-completed')]
    public function handleUpload(array $data): void
    {
        // $data contains:
        // - uploadId: string
        // - fileName: string
        // - fileSize: int
        // - finalPath: string
        // - disk: string

        $this->uploads[] = [
            'name' => $data['fileName'],
            'path' => $data['finalPath'],
        ];

        // Or process the file
        Storage::disk($data['disk'])->move(
            $data['finalPath'],
            "documents/{$data['fileName']}",
        );
    }

    public function render()
    {
        return view('livewire.document-manager');
    }
}
```

```blade
{{-- livewire/document-manager.blade.php --}}
<div>
    <livewire:chunky-upload context="documents" />

    <ul>
        @foreach($uploads as $upload)
            <li>{{ $upload['name'] }}</li>
        @endforeach
    </ul>
</div>
```

### With Context Save Callback

For automatic post-upload processing (e.g., Spatie Media Library):

```php
// AppServiceProvider::boot()
use NETipar\Chunky\Facades\Chunky;

Chunky::context('profile_avatar', save: function ($metadata) {
    $user = auth()->user();
    $user->addMediaFromDisk($metadata->finalPath, $metadata->disk)
        ->toMediaCollection('avatar');
});
```

```blade
{{-- The save callback runs automatically after assembly --}}
<livewire:chunky-upload context="profile_avatar" />
```

## How It Works

The Livewire component acts as a bridge:

1. **Chunk uploads** go directly to the Chunky API endpoints (via `fetch()` in Alpine.js) -- they do NOT go through Livewire's wire protocol
2. **Completion notification** is sent via Alpine.js `$dispatch` -> Livewire `$wire`, which calls `completeUpload()` on the server
3. The Livewire component verifies the upload status and dispatches the `chunky-upload-completed` event to the parent

This means:
- No Livewire upload limits apply (chunks bypass Livewire)
- Progress updates are real-time (Alpine.js, no server roundtrips)
- Server-side verification still happens on completion
