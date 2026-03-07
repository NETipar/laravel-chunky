# Basic File Upload

The simplest way to upload a large file using Chunky.

## Vue 3

```vue
<script setup lang="ts">
import { useChunkUpload } from '@netipar/chunky-vue3';

const { upload, progress, isUploading, isComplete, error } = useChunkUpload();

function onFileChange(event: Event) {
    const input = event.target as HTMLInputElement;
    if (input.files?.[0]) {
        upload(input.files[0]);
    }
}
</script>

<template>
    <div>
        <input type="file" @change="onFileChange" :disabled="isUploading" />

        <div v-if="isUploading">
            <progress :value="progress" max="100" />
            <span>{{ progress }}%</span>
        </div>

        <p v-if="isComplete" class="text-green-600">Upload complete!</p>
        <p v-if="error" class="text-red-600">{{ error }}</p>
    </div>
</template>
```

## React

```tsx
import { useChunkUpload } from '@netipar/chunky-react';

function FileUpload() {
    const { upload, progress, isUploading, isComplete, error } = useChunkUpload();

    const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file) upload(file);
    };

    return (
        <div>
            <input type="file" onChange={handleChange} disabled={isUploading} />

            {isUploading && (
                <div>
                    <progress value={progress} max={100} />
                    <span>{progress}%</span>
                </div>
            )}

            {isComplete && <p style={{ color: 'green' }}>Upload complete!</p>}
            {error && <p style={{ color: 'red' }}>{error}</p>}
        </div>
    );
}
```

## Alpine.js

```html
<div x-data="chunkUpload()">
    <input type="file" x-on:change="handleFileInput($event)" :disabled="isUploading" />

    <template x-if="isUploading">
        <div>
            <progress :value="progress" max="100"></progress>
            <span x-text="Math.round(progress) + '%'"></span>
        </div>
    </template>

    <template x-if="isComplete">
        <p style="color: green;">Upload complete!</p>
    </template>

    <template x-if="error">
        <p style="color: red;" x-text="error"></p>
    </template>
</div>
```

Don't forget to register the Alpine plugin:

```js
import { registerChunkUpload } from '@netipar/chunky-alpine';
import Alpine from 'alpinejs';

registerChunkUpload(Alpine);
Alpine.start();
```

## Livewire

```blade
<livewire:chunky-upload />
```

The built-in component provides a complete UI with dropzone, progress bar, and error handling out of the box.

## With Metadata

Pass custom metadata that will be stored alongside the upload:

```vue
<script setup lang="ts">
import { useChunkUpload } from '@netipar/chunky-vue3';

const { upload, progress, isUploading } = useChunkUpload();

function onFileChange(event: Event) {
    const input = event.target as HTMLInputElement;
    if (input.files?.[0]) {
        upload(input.files[0], {
            folder: 'documents',
            description: 'Annual report 2026',
            user_id: 42,
        });
    }
}
</script>
```

## With Context

Use a context to apply server-side validation rules:

```vue
<script setup lang="ts">
import { useChunkUpload } from '@netipar/chunky-vue3';

// This will apply the 'profile_avatar' validation rules on the server
const { upload } = useChunkUpload({ context: 'profile_avatar' });
</script>
```

```tsx
// React equivalent
import { useChunkUpload } from '@netipar/chunky-react';

const { upload } = useChunkUpload({ context: 'profile_avatar' });
```

## Backend Listener

Create a listener to process the completed upload:

```php
namespace App\Listeners;

use NETipar\Chunky\Events\UploadCompleted;
use Illuminate\Support\Facades\Storage;

class ProcessUploadedFile
{
    public function handle(UploadCompleted $event): void
    {
        // $event->uploadId  - UUID of the upload
        // $event->finalPath - Path on disk (e.g. 'chunky/uploads/{id}/report.pdf')
        // $event->disk      - Laravel disk name (e.g. 'local')
        // $event->metadata  - Custom metadata passed from frontend

        // Move to permanent location
        $permanentPath = "documents/{$event->metadata['folder']}/{$event->uploadId}.pdf";
        Storage::disk($event->disk)->move($event->finalPath, $permanentPath);

        // Create a database record
        Document::create([
            'path' => $permanentPath,
            'disk' => $event->disk,
            'description' => $event->metadata['description'] ?? null,
            'user_id' => $event->metadata['user_id'] ?? null,
        ]);
    }
}
```

Register the listener:

```php
// EventServiceProvider
protected $listen = [
    \NETipar\Chunky\Events\UploadCompleted::class => [
        \App\Listeners\ProcessUploadedFile::class,
    ],
];
```
