<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, type PropType } from 'vue';
import Dropzone from 'dropzone';
import { useChunkUpload } from '../useChunkUpload';
import type { ChunkUploadOptions, UploadError, UploadResult, ProgressEvent as ChunkProgress } from '@netipar/chunky-core';

interface DropzoneOptions {
    url?: string;
    acceptedFiles?: string;
    maxFilesize?: number;
    dictDefaultMessage?: string;
    [key: string]: unknown;
}

const props = defineProps({
    options: {
        type: Object as PropType<DropzoneOptions>,
        default: () => ({}),
    },
    chunkOptions: {
        type: Object as PropType<ChunkUploadOptions>,
        default: () => ({}),
    },
});

const emit = defineEmits<{
    'upload-initiated': [uploadId: string];
    'chunk-uploaded': [chunkIndex: number, uploadId: string];
    'upload-complete': [result: UploadResult];
    'upload-error': [error: UploadError];
    'progress': [event: ChunkProgress];
}>();

const dropzoneRef = ref<HTMLElement | null>(null);
let dropzoneInstance: Dropzone | null = null;

const chunkUpload = useChunkUpload(props.chunkOptions);

chunkUpload.onProgress((event) => emit('progress', event));
chunkUpload.onChunkUploaded((chunk) => emit('chunk-uploaded', chunk.index, chunk.uploadId));
chunkUpload.onComplete((result) => emit('upload-complete', result));
chunkUpload.onError((err) => emit('upload-error', err));

async function handleFile(file: Dropzone.DropzoneFile): Promise<void> {
    try {
        const nativeFile = file as unknown as File;
        const result = await chunkUpload.upload(nativeFile);
        emit('upload-initiated', result.uploadId);
    } catch {
        // Error already emitted via onError callback
    }
}

onMounted(() => {
    if (!dropzoneRef.value) {
        return;
    }

    dropzoneInstance = new Dropzone(dropzoneRef.value, {
        url: '/dev/null',
        autoProcessQueue: false,
        ...props.options,
    });

    dropzoneInstance.on('addedfile', (file) => {
        handleFile(file);
    });
});

onBeforeUnmount(() => {
    dropzoneInstance?.destroy();
});

defineExpose({
    pause: chunkUpload.pause,
    resume: chunkUpload.resume,
    cancel: chunkUpload.cancel,
    retry: chunkUpload.retry,
    progress: chunkUpload.progress,
    isUploading: chunkUpload.isUploading,
    isPaused: chunkUpload.isPaused,
    isComplete: chunkUpload.isComplete,
    error: chunkUpload.error,
});
</script>

<template>
    <div ref="dropzoneRef" class="chunk-dropzone">
        <slot>
            <div class="chunk-dropzone__default">
                <p>Drop files here or click to upload</p>
            </div>
        </slot>
        <slot
            name="preview"
            :progress="chunkUpload.progress.value"
            :is-uploading="chunkUpload.isUploading.value"
            :is-complete="chunkUpload.isComplete.value"
            :error="chunkUpload.error.value"
            :current-file="chunkUpload.currentFile.value"
        />
    </div>
</template>
