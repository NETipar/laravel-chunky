import type { BatchUploadOptions, BatchResult, JsonObject } from '@netipar/chunky-core';
import { useBatchUpload, type BatchUploadReturn } from './useBatchUpload';

/**
 * Polymorphic upload hook. Accepts either a single `File` or `File[]`
 * — internally uses `useBatchUpload` because every upload is a batch
 * of N files. The signature matches the v1.0 canonical API.
 *
 * Prefer this over `useBatchUpload` / `useChunkUpload` for new code.
 * The two specific hooks remain for back-compat.
 */
export interface UploadReturn extends Omit<BatchUploadReturn, 'upload' | 'enqueue'> {
    upload: (input: File | File[], metadata?: JsonObject) => Promise<BatchResult>;
    enqueue: (input: File | File[], metadata?: JsonObject) => Promise<BatchResult>;
}

export function useUpload(options: BatchUploadOptions = {}): UploadReturn {
    const inner = useBatchUpload(options);

    const toArray = (input: File | File[]): File[] => Array.isArray(input) ? input : [input];

    return {
        ...inner,
        upload: (input, metadata) => inner.upload(toArray(input), metadata),
        enqueue: (input, metadata) => inner.enqueue(toArray(input), metadata),
    };
}
