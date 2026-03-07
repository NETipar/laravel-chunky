# ChunkDropzone Component (Vue 3)

A Dropzone.js wrapper that replaces the default upload mechanism with chunk-based uploads. This component is part of the `@netipar/chunky-vue3` package.

## Requirements

Install Dropzone.js as a peer dependency:

```bash
npm install dropzone
```

## Basic Usage

```vue
<script setup lang="ts">
import { ChunkDropzone } from '@netipar/chunky-vue3';
import type { UploadResult } from '@netipar/chunky-core';

function onComplete(result: UploadResult) {
    console.log('Upload complete:', result.uploadId, result.fileName);
}
</script>

<template>
    <ChunkDropzone
        @upload-complete="onComplete"
        @upload-error="(err) => console.error(err)"
    >
        <div class="flex flex-col items-center justify-center p-12">
            <svg class="h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
            </svg>
            <p class="mt-2 text-gray-500">Drop files here or click to upload</p>
        </div>
    </ChunkDropzone>
</template>
```

## With Dropzone Options

```vue
<ChunkDropzone
    :options="{
        acceptedFiles: '.pdf,.zip,.mp4,.mov',
        maxFilesize: 2048,
        dictDefaultMessage: 'Drop your files here',
    }"
    :chunk-options="{
        maxConcurrent: 5,
        maxRetries: 5,
    }"
    @upload-complete="onComplete"
    @progress="onProgress"
>
    <p>Accepted: PDF, ZIP, MP4, MOV (max 2GB)</p>
</ChunkDropzone>
```

## With Context

```vue
<ChunkDropzone
    :chunk-options="{ context: 'documents' }"
    @upload-complete="onComplete"
>
    <p>Drop documents here</p>
</ChunkDropzone>
```

## With Preview Slot

```vue
<ChunkDropzone @upload-complete="onComplete">
    <template #default>
        <p class="p-8 text-center text-gray-500">Drop files here</p>
    </template>

    <template #preview="{ progress, isUploading, isComplete, error, currentFile }">
        <div v-if="currentFile" class="border-t p-4">
            <div class="flex items-center justify-between">
                <span>{{ currentFile.name }}</span>
                <span v-if="isUploading">{{ progress }}%</span>
                <span v-if="isComplete" class="text-green-600">Done</span>
                <span v-if="error" class="text-red-600">Failed</span>
            </div>
            <div v-if="isUploading" class="mt-2 h-1 w-full rounded bg-gray-200">
                <div class="h-1 rounded bg-indigo-500" :style="{ width: `${progress}%` }" />
            </div>
        </div>
    </template>
</ChunkDropzone>
```

## Accessing Controls via Ref

```vue
<script setup lang="ts">
import { ref } from 'vue';
import { ChunkDropzone } from '@netipar/chunky-vue3';

const dropzoneRef = ref<InstanceType<typeof ChunkDropzone>>();

function pauseAll() {
    dropzoneRef.value?.pause();
}
</script>

<template>
    <ChunkDropzone ref="dropzoneRef" @upload-complete="onComplete">
        <p>Drop files here</p>
    </ChunkDropzone>

    <button @click="pauseAll">Pause</button>
    <button @click="dropzoneRef?.resume()">Resume</button>
    <button @click="dropzoneRef?.cancel()">Cancel</button>
</template>
```

## Events

| Event | Payload | Description |
|-------|---------|-------------|
| `upload-initiated` | `uploadId: string` | Upload started |
| `chunk-uploaded` | `chunkIndex: number, uploadId: string` | Single chunk done |
| `upload-complete` | `UploadResult` | All chunks assembled |
| `upload-error` | `UploadError` | Upload failed |
| `progress` | `ProgressEvent` | Progress update |
