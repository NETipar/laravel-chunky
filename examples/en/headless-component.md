# Headless Component (Vue 3)

The `HeadlessChunkUpload` component renders no DOM of its own. It exposes all state and methods through a scoped slot, giving you full control over the UI. This component is part of the `@netipar/chunky-vue3` package.

## Basic Usage

```vue
<script setup lang="ts">
import { HeadlessChunkUpload } from '@netipar/chunky-vue3';
</script>

<template>
    <HeadlessChunkUpload v-slot="{
        upload, pause, resume, cancel, retry,
        progress, isUploading, isPaused, isComplete, error,
        uploadedChunks, totalChunks, currentFile
    }">
        <div class="rounded-lg border p-6">
            <!-- File input -->
            <div v-if="!isUploading && !isComplete">
                <input
                    type="file"
                    @change="upload($event.target.files[0])"
                />
            </div>

            <!-- Progress -->
            <div v-if="isUploading" class="space-y-3">
                <p>{{ currentFile?.name }} - {{ progress }}%</p>

                <div class="h-2 w-full rounded bg-gray-200">
                    <div
                        class="h-2 rounded bg-indigo-500"
                        :style="{ width: `${progress}%` }"
                    />
                </div>

                <p class="text-sm text-gray-500">
                    {{ uploadedChunks }} / {{ totalChunks }} chunks
                </p>

                <div class="flex gap-2">
                    <button @click="isPaused ? resume() : pause()">
                        {{ isPaused ? 'Resume' : 'Pause' }}
                    </button>
                    <button @click="cancel">Cancel</button>
                </div>
            </div>

            <!-- Complete -->
            <div v-if="isComplete" class="text-green-600">
                Upload complete!
            </div>

            <!-- Error -->
            <div v-if="error" class="text-red-600">
                <p>{{ error }}</p>
                <button @click="retry">Retry</button>
            </div>
        </div>
    </HeadlessChunkUpload>
</template>
```

## With Custom Options

```vue
<HeadlessChunkUpload
    :options="{
        maxConcurrent: 5,
        maxRetries: 5,
        context: 'documents',
        headers: { 'X-Custom-Header': 'value' },
    }"
    v-slot="{ upload, progress, isUploading }"
>
    <!-- Your custom UI -->
</HeadlessChunkUpload>
```

## Accessing Component Methods via Ref

```vue
<script setup lang="ts">
import { ref } from 'vue';
import { HeadlessChunkUpload } from '@netipar/chunky-vue3';

const uploaderRef = ref<InstanceType<typeof HeadlessChunkUpload>>();

function pauseUpload() {
    uploaderRef.value?.pause();
}
</script>

<template>
    <HeadlessChunkUpload ref="uploaderRef" v-slot="{ upload, isUploading }">
        <input type="file" @change="upload($event.target.files[0])" />
    </HeadlessChunkUpload>

    <button @click="pauseUpload">Pause from outside</button>
</template>
```
