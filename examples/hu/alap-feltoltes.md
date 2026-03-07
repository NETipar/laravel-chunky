# Alap feltöltés

A legegyszerűbb mód nagy fájlok feltöltésére a Chunky csomag használatával.

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

        <p v-if="isComplete" class="text-green-600">Feltöltés kész!</p>
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

            {isComplete && <p style={{ color: 'green' }}>Feltöltés kész!</p>}
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
        <p style="color: green;">Feltöltés kész!</p>
    </template>

    <template x-if="error">
        <p style="color: red;" x-text="error"></p>
    </template>
</div>
```

Az Alpine plugin regisztrálása:

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

A beépített komponens teljes UI-t biztosít: dropzone, progress bar, és hibakezelés.

## Metaadatokkal

Egyedi metaadatok átadása, amelyek a feltöltés mellett tárolódnak:

```vue
<script setup lang="ts">
import { useChunkUpload } from '@netipar/chunky-vue3';

const { upload, progress, isUploading } = useChunkUpload();

function onFileChange(event: Event) {
    const input = event.target as HTMLInputElement;
    if (input.files?.[0]) {
        upload(input.files[0], {
            folder: 'dokumentumok',
            description: 'Éves jelentés 2026',
            user_id: 42,
        });
    }
}
</script>
```

## Kontextussal

Szerver oldali validációs szabályok alkalmazása kontextussal:

```vue
<script setup lang="ts">
import { useChunkUpload } from '@netipar/chunky-vue3';

// A 'profile_avatar' kontextus validációs szabályait alkalmazza a szerveren
const { upload } = useChunkUpload({ context: 'profile_avatar' });
</script>
```

```tsx
// React megfelelője
import { useChunkUpload } from '@netipar/chunky-react';

const { upload } = useChunkUpload({ context: 'profile_avatar' });
```

## Backend Listener

Listener létrehozása a befejezett feltöltés feldolgozásához:

```php
namespace App\Listeners;

use NETipar\Chunky\Events\UploadCompleted;
use Illuminate\Support\Facades\Storage;

class ProcessUploadedFile
{
    public function handle(UploadCompleted $event): void
    {
        // $event->uploadId  - A feltöltés UUID-je
        // $event->finalPath - Útvonal a lemezen (pl. 'chunky/uploads/{id}/jelentes.pdf')
        // $event->disk      - Laravel lemez neve (pl. 'local')
        // $event->metadata  - Frontendről kapott egyedi metaadatok

        $permanentPath = "dokumentumok/{$event->metadata['folder']}/{$event->uploadId}.pdf";
        Storage::disk($event->disk)->move($event->finalPath, $permanentPath);

        Document::create([
            'path' => $permanentPath,
            'disk' => $event->disk,
            'description' => $event->metadata['description'] ?? null,
            'user_id' => $event->metadata['user_id'] ?? null,
        ]);
    }
}
```

Listener regisztrálása:

```php
// EventServiceProvider
protected $listen = [
    \NETipar\Chunky\Events\UploadCompleted::class => [
        \App\Listeners\ProcessUploadedFile::class,
    ],
];
```
