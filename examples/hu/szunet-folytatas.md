# Szünet, Folytatás és Megszakítás

Nagy fájlok feltöltése teljes kontrollal a feltöltés életciklusa felett.

## Vue 3

```vue
<script setup lang="ts">
import { useChunkUpload } from '@netipar/chunky-vue3';

const {
    upload, pause, resume, cancel, retry,
    progress, isUploading, isPaused, isComplete, error,
    uploadedChunks, totalChunks, currentFile,
} = useChunkUpload({
    maxConcurrent: 3,
    autoRetry: true,
    maxRetries: 3,
});

function onFileChange(event: Event) {
    const input = event.target as HTMLInputElement;
    if (input.files?.[0]) {
        upload(input.files[0]);
    }
}

function formatSize(bytes: number): string {
    if (bytes >= 1_073_741_824) return `${(bytes / 1_073_741_824).toFixed(1)} GB`;
    if (bytes >= 1_048_576) return `${(bytes / 1_048_576).toFixed(1)} MB`;
    return `${(bytes / 1024).toFixed(1)} KB`;
}
</script>

<template>
    <div class="space-y-4">
        <div v-if="!isUploading && !isComplete">
            <label class="block cursor-pointer rounded-lg border-2 border-dashed border-gray-300 p-8 text-center hover:border-indigo-400">
                <input type="file" class="hidden" @change="onFileChange" />
                <p class="text-gray-500">Kattints a fájl kiválasztásához</p>
            </label>
        </div>

        <div v-if="isUploading || isPaused" class="space-y-2">
            <div class="flex items-center justify-between text-sm">
                <span>{{ currentFile?.name }}</span>
                <span>{{ formatSize(currentFile?.size ?? 0) }}</span>
            </div>

            <div class="h-3 w-full overflow-hidden rounded-full bg-gray-200">
                <div
                    class="h-full rounded-full bg-indigo-500 transition-all"
                    :style="{ width: `${progress}%` }"
                />
            </div>

            <div class="flex items-center justify-between text-sm text-gray-500">
                <span>{{ uploadedChunks }} / {{ totalChunks }} chunk</span>
                <span>{{ progress }}%</span>
            </div>

            <div class="flex gap-2">
                <button
                    class="rounded bg-yellow-500 px-4 py-2 text-white"
                    @click="isPaused ? resume() : pause()"
                >
                    {{ isPaused ? 'Folytatás' : 'Szünet' }}
                </button>
                <button class="rounded bg-red-500 px-4 py-2 text-white" @click="cancel">
                    Megszakítás
                </button>
            </div>
        </div>

        <div v-if="isComplete" class="rounded bg-green-100 p-4 text-green-800">
            Feltöltés kész!
        </div>

        <div v-if="error" class="rounded bg-red-100 p-4 text-red-800">
            <p>{{ error }}</p>
            <button class="mt-2 rounded bg-red-500 px-4 py-2 text-white" @click="retry">
                Újrapróbálás
            </button>
        </div>
    </div>
</template>
```

## React

```tsx
import { useChunkUpload } from '@netipar/chunky-react';

function FileUpload() {
    const {
        upload, pause, resume, cancel, retry,
        progress, isUploading, isPaused, isComplete, error,
        uploadedChunks, totalChunks, currentFile,
    } = useChunkUpload({ maxConcurrent: 3 });

    const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file) upload(file);
    };

    return (
        <div className="space-y-4">
            {!isUploading && !isComplete && (
                <label className="block cursor-pointer border-2 border-dashed rounded-lg p-8 text-center">
                    <input type="file" className="hidden" onChange={handleChange} />
                    <p>Kattints a fájl kiválasztásához</p>
                </label>
            )}

            {(isUploading || isPaused) && (
                <div className="space-y-2">
                    <p>{currentFile?.name}</p>
                    <progress value={progress} max={100} className="w-full" />
                    <p>{uploadedChunks} / {totalChunks} chunk - {Math.round(progress)}%</p>
                    <div className="flex gap-2">
                        <button onClick={isPaused ? resume : pause}>
                            {isPaused ? 'Folytatás' : 'Szünet'}
                        </button>
                        <button onClick={cancel}>Megszakítás</button>
                    </div>
                </div>
            )}

            {isComplete && <p className="text-green-600">Feltöltés kész!</p>}

            {error && (
                <div className="text-red-600">
                    <p>{error}</p>
                    <button onClick={retry}>Újrapróbálás</button>
                </div>
            )}
        </div>
    );
}
```

## Alpine.js

```html
<div x-data="chunkUpload({ maxConcurrent: 3 })">
    <template x-if="!isUploading && !isComplete">
        <label class="block cursor-pointer border-2 border-dashed rounded-lg p-8 text-center">
            <input type="file" class="hidden" x-on:change="handleFileInput($event)" />
            <p>Kattints a fájl kiválasztásához</p>
        </label>
    </template>

    <template x-if="isUploading || isPaused">
        <div class="space-y-2">
            <span x-text="currentFile?.name"></span>
            <progress :value="progress" max="100" class="w-full"></progress>
            <span x-text="uploadedChunks + ' / ' + totalChunks + ' chunk - ' + Math.round(progress) + '%'"></span>
            <div class="flex gap-2">
                <button x-on:click="isPaused ? resume() : pause()" x-text="isPaused ? 'Folytatás' : 'Szünet'"></button>
                <button x-on:click="cancel()">Megszakítás</button>
            </div>
        </div>
    </template>

    <template x-if="isComplete">
        <p class="text-green-600">Feltöltés kész!</p>
    </template>

    <template x-if="error">
        <div class="text-red-600">
            <p x-text="error"></p>
            <button x-on:click="retry()">Újrapróbálás</button>
        </div>
    </template>
</div>
```

## Hogyan működik a folytatás?

Amikor meghívod a `pause()`, majd a `resume()` függvényt:

1. Az uploader megjegyzi, mely chunk-ok lettek már feltöltve
2. Folytatáskor meghívja a `GET /api/chunky/upload/{uploadId}` endpoint-ot a szerver oldali állapot ellenőrzésére
3. Csak a hiányzó chunk-okat tölti fel újra
4. Ez oldal újratöltés után is működik, ha elmented az `uploadId`-t

### Folytatás oldal újratöltés után (Vue 3)

```vue
<script setup lang="ts">
import { useChunkUpload } from '@netipar/chunky-vue3';
import { onMounted, watch } from 'vue';

const { upload, uploadId, progress, isUploading } = useChunkUpload();

function startUpload(file: File) {
    upload(file).then(() => {
        localStorage.removeItem('chunky_upload_id');
    });

    const unwatch = watch(uploadId, (id) => {
        if (id) {
            localStorage.setItem('chunky_upload_id', id);
            unwatch();
        }
    });
}

onMounted(() => {
    const savedId = localStorage.getItem('chunky_upload_id');
    if (savedId) {
        uploadId.value = savedId;
        // A felhasználónak újra ki kell választania ugyanazt a fájlt
    }
});
</script>
```
