# Pause, Resume & Cancel

Upload large files with full control over the upload lifecycle.

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
                <p class="text-gray-500">Click to select a file</p>
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
                <span>{{ uploadedChunks }} / {{ totalChunks }} chunks</span>
                <span>{{ progress }}%</span>
            </div>

            <div class="flex gap-2">
                <button
                    class="rounded bg-yellow-500 px-4 py-2 text-white"
                    @click="isPaused ? resume() : pause()"
                >
                    {{ isPaused ? 'Resume' : 'Pause' }}
                </button>
                <button class="rounded bg-red-500 px-4 py-2 text-white" @click="cancel">
                    Cancel
                </button>
            </div>
        </div>

        <div v-if="isComplete" class="rounded bg-green-100 p-4 text-green-800">
            Upload complete!
        </div>

        <div v-if="error" class="rounded bg-red-100 p-4 text-red-800">
            <p>{{ error }}</p>
            <button class="mt-2 rounded bg-red-500 px-4 py-2 text-white" @click="retry">
                Retry
            </button>
        </div>
    </div>
</template>
```

## React

```tsx
import { useChunkUpload } from '@netipar/chunky-react';

function formatSize(bytes: number): string {
    if (bytes >= 1_073_741_824) return `${(bytes / 1_073_741_824).toFixed(1)} GB`;
    if (bytes >= 1_048_576) return `${(bytes / 1_048_576).toFixed(1)} MB`;
    return `${(bytes / 1024).toFixed(1)} KB`;
}

function FileUpload() {
    const {
        upload, pause, resume, cancel, retry,
        progress, isUploading, isPaused, isComplete, error,
        uploadedChunks, totalChunks, currentFile,
    } = useChunkUpload({ maxConcurrent: 3, autoRetry: true, maxRetries: 3 });

    const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file) upload(file);
    };

    if (isComplete) {
        return <div className="rounded bg-green-100 p-4 text-green-800">Upload complete!</div>;
    }

    if (error) {
        return (
            <div className="rounded bg-red-100 p-4 text-red-800">
                <p>{error}</p>
                <button onClick={retry}>Retry</button>
            </div>
        );
    }

    return (
        <div className="space-y-4">
            {!isUploading && (
                <label className="block cursor-pointer rounded-lg border-2 border-dashed p-8 text-center">
                    <input type="file" className="hidden" onChange={handleChange} />
                    <p>Click to select a file</p>
                </label>
            )}

            {(isUploading || isPaused) && (
                <div className="space-y-2">
                    <div className="flex justify-between text-sm">
                        <span>{currentFile?.name}</span>
                        <span>{formatSize(currentFile?.size ?? 0)}</span>
                    </div>

                    <progress value={progress} max={100} className="w-full" />

                    <div className="flex justify-between text-sm text-gray-500">
                        <span>{uploadedChunks} / {totalChunks} chunks</span>
                        <span>{progress}%</span>
                    </div>

                    <div className="flex gap-2">
                        <button onClick={isPaused ? resume : pause}>
                            {isPaused ? 'Resume' : 'Pause'}
                        </button>
                        <button onClick={cancel}>Cancel</button>
                    </div>
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
        <label class="block cursor-pointer rounded-lg border-2 border-dashed p-8 text-center">
            <input type="file" class="hidden" x-on:change="handleFileInput($event)" />
            <p>Click to select a file</p>
        </label>
    </template>

    <template x-if="isUploading || isPaused">
        <div class="space-y-2">
            <div class="flex justify-between text-sm">
                <span x-text="currentFile?.name"></span>
            </div>

            <progress :value="progress" max="100" class="w-full"></progress>

            <div class="flex justify-between text-sm text-gray-500">
                <span x-text="uploadedChunks + ' / ' + totalChunks + ' chunks'"></span>
                <span x-text="Math.round(progress) + '%'"></span>
            </div>

            <div class="flex gap-2">
                <button x-on:click="isPaused ? resume() : pause()" x-text="isPaused ? 'Resume' : 'Pause'"></button>
                <button x-on:click="cancel()">Cancel</button>
            </div>
        </div>
    </template>

    <template x-if="isComplete">
        <div class="rounded bg-green-100 p-4 text-green-800">Upload complete!</div>
    </template>

    <template x-if="error">
        <div class="rounded bg-red-100 p-4 text-red-800">
            <p x-text="error"></p>
            <button x-on:click="retry()">Retry</button>
        </div>
    </template>
</div>
```

## How Resume Works

When you call `pause()` and then `resume()`:

1. The uploader remembers which chunks have been uploaded
2. On resume, it calls `GET /api/chunky/upload/{uploadId}` to verify server-side state
3. Only missing chunks are re-uploaded
4. This also works across page reloads if you persist the `uploadId`

### Resume After Page Reload (Vue 3)

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
        // User needs to re-select the same file to resume
    }
});
</script>
```
