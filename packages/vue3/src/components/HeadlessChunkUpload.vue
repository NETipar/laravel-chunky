<script setup lang="ts">
import { useChunkUpload, type ChunkUploadReturn } from '../useChunkUpload';
import type { ChunkUploadOptions } from '@netipar/chunky-core';

interface Props {
    options?: ChunkUploadOptions;
}

const props = withDefaults(defineProps<Props>(), {
    options: () => ({}),
});

const chunkUpload = useChunkUpload(props.options);

defineExpose<ChunkUploadReturn>(chunkUpload);
</script>

<template>
    <slot
        :progress="chunkUpload.progress.value"
        :is-uploading="chunkUpload.isUploading.value"
        :is-paused="chunkUpload.isPaused.value"
        :is-complete="chunkUpload.isComplete.value"
        :error="chunkUpload.error.value"
        :upload-id="chunkUpload.uploadId.value"
        :uploaded-chunks="chunkUpload.uploadedChunks.value"
        :total-chunks="chunkUpload.totalChunks.value"
        :current-file="chunkUpload.currentFile.value"
        :upload="chunkUpload.upload"
        :pause="chunkUpload.pause"
        :resume="chunkUpload.resume"
        :cancel="chunkUpload.cancel"
        :retry="chunkUpload.retry"
    />
</template>
