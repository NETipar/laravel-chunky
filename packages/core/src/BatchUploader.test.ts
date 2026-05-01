import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { BatchUploader } from './BatchUploader';
import type { UploadError } from './types';
import { makeFile, mockFetchSequence } from './__tests__/helpers';

const ORIGINAL_FETCH = globalThis.fetch;

beforeEach(() => {
    // Suppress the document-cookie default the helper sets up.
    vi.spyOn(document, 'cookie', 'get').mockReturnValue('');
});

afterEach(() => {
    globalThis.fetch = ORIGINAL_FETCH;
    vi.restoreAllMocks();
});

function batchInitiateResponse(batchId = 'batch-1') {
    return { status: 200, json: { batch_id: batchId } };
}

function uploadInitiateResponse(uploadId: string, totalChunks: number, chunkSize = 1024) {
    return {
        status: 200,
        json: {
            upload_id: uploadId,
            chunk_size: chunkSize,
            total_chunks: totalChunks,
        },
    };
}

function chunkResponse(uploadedCount: number, totalChunks: number) {
    return {
        status: 200,
        json: {
            chunk_index: 0,
            is_complete: uploadedCount >= totalChunks,
            uploaded_count: uploadedCount,
            total_chunks: totalChunks,
            progress: (uploadedCount / totalChunks) * 100,
        },
    };
}

describe('BatchUploader basics', () => {
    it('uploads a single file as a batch of 1', async () => {
        globalThis.fetch = mockFetchSequence([
            batchInitiateResponse(),
            uploadInitiateResponse('upload-1', 1),
            chunkResponse(1, 1),
        ]);

        const uploader = new BatchUploader({ maxConcurrentFiles: 1 });
        const result = await uploader.upload([makeFile('a.bin', 1024)]);

        expect(result.batchId).toBe('batch-1');
        expect(result.totalFiles).toBe(1);
        expect(result.completedFiles).toBe(1);
        expect(result.failedFiles).toBe(0);
    });

    it('emits stateChange events that mirror the public state', async () => {
        globalThis.fetch = mockFetchSequence([
            batchInitiateResponse(),
            uploadInitiateResponse('upload-2', 1),
            chunkResponse(1, 1),
        ]);

        const states: Array<{ isUploading: boolean; isComplete: boolean }> = [];
        const uploader = new BatchUploader();
        uploader.on('stateChange', (s) => states.push({ isUploading: s.isUploading, isComplete: s.isComplete }));

        await uploader.upload([makeFile('a.bin')]);

        expect(states.some((s) => s.isUploading)).toBe(true);
        expect(states[states.length - 1].isComplete).toBe(true);
    });

    it('counts failed files when one fails', async () => {
        globalThis.fetch = mockFetchSequence([
            batchInitiateResponse(),
            uploadInitiateResponse('u-1', 1),
            chunkResponse(1, 1),
            uploadInitiateResponse('u-2', 1),
            { status: 500, json: { message: 'boom' } }, // failing chunk
        ]);

        const uploader = new BatchUploader({ maxConcurrentFiles: 1, autoRetry: false });
        const result = await uploader.upload([makeFile('a.bin'), makeFile('b.bin')]);

        expect(result.completedFiles).toBe(1);
        expect(result.failedFiles).toBe(1);
    });

    it('exposes UploadError with cancelled flag set to false on transport failure', async () => {
        globalThis.fetch = mockFetchSequence([
            batchInitiateResponse(),
            uploadInitiateResponse('u-x', 1),
            { status: 500, json: { message: 'boom' } },
        ]);

        const errors: UploadError[] = [];
        const uploader = new BatchUploader({ maxConcurrentFiles: 1, autoRetry: false });
        uploader.on('fileError', (err) => errors.push(err));

        await uploader.upload([makeFile('a.bin')]);

        expect(errors).toHaveLength(1);
        // Not user-cancelled, so the flag should be false-y.
        expect(errors[0].cancelled).toBeFalsy();
    });
});

describe('BatchUploader memory & cleanup', () => {
    it('drops finished per-file uploaders so they cannot leak', async () => {
        globalThis.fetch = mockFetchSequence([
            batchInitiateResponse(),
            uploadInitiateResponse('u-1', 1),
            chunkResponse(1, 1),
            uploadInitiateResponse('u-2', 1),
            chunkResponse(1, 1),
            uploadInitiateResponse('u-3', 1),
            chunkResponse(1, 1),
        ]);

        const uploader = new BatchUploader({ maxConcurrentFiles: 1 });
        await uploader.upload([
            makeFile('a.bin'),
            makeFile('b.bin'),
            makeFile('c.bin'),
        ]);

        // The internal uploaders array should have been spliced down to
        // empty at the end of upload() — accessing it via cancel() (which
        // iterates and calls per-uploader cancel) must be a no-op.
        expect(() => uploader.cancel()).not.toThrow();
    });

    it('rejects pending queued batches on cancel', async () => {
        globalThis.fetch = mockFetchSequence([
            // First batch starts...
            batchInitiateResponse('first'),
            uploadInitiateResponse('first-1', 1),
            chunkResponse(1, 1),
        ]);

        const uploader = new BatchUploader();
        // Schedule a queued batch BEFORE the first finishes by also queueing
        // synchronously after upload() starts.
        const inFlight = uploader.upload([makeFile('f1.bin')]);
        const queued = uploader.enqueue([makeFile('q1.bin')]);

        // Cancel before the queued batch can run.
        uploader.cancel();

        // The queued promise should reject with the cancel reason.
        await expect(queued).rejects.toThrow(/cancelled/i);

        // The in-flight batch ALSO rejects on cancel, swallow that.
        await inFlight.catch(() => {});
    });
});

describe('BatchUploader sticky-replay guard', () => {
    it('does not deliver a sticky complete to a synchronously-unsubscribed listener', async () => {
        globalThis.fetch = mockFetchSequence([
            batchInitiateResponse(),
            uploadInitiateResponse('u-1', 1),
            chunkResponse(1, 1),
        ]);

        const uploader = new BatchUploader();
        await uploader.upload([makeFile('a.bin')]);

        let called = 0;
        const unsub = uploader.on('complete', () => {
            called++;
        });
        unsub(); // Synchronous unsubscribe before microtask fires.

        // Wait one microtask cycle.
        await Promise.resolve();
        await Promise.resolve();

        expect(called).toBe(0);
    });

    it('does deliver sticky complete to a still-subscribed late listener', async () => {
        globalThis.fetch = mockFetchSequence([
            batchInitiateResponse(),
            uploadInitiateResponse('u-1', 1),
            chunkResponse(1, 1),
        ]);

        const uploader = new BatchUploader();
        await uploader.upload([makeFile('a.bin')]);

        let called = 0;
        uploader.on('complete', () => {
            called++;
        });

        // Wait for microtask delivery.
        await Promise.resolve();
        await Promise.resolve();

        expect(called).toBe(1);
    });
});

describe('BatchUploader queue behaviour', () => {
    it('enqueue() runs the second batch after the first completes', async () => {
        globalThis.fetch = mockFetchSequence([
            // First batch
            batchInitiateResponse('first'),
            uploadInitiateResponse('first-1', 1),
            chunkResponse(1, 1),
            // Second batch
            batchInitiateResponse('second'),
            uploadInitiateResponse('second-1', 1),
            chunkResponse(1, 1),
        ]);

        const uploader = new BatchUploader();
        const a = uploader.upload([makeFile('a.bin')]);
        const b = uploader.enqueue([makeFile('b.bin')]);

        const ar = await a;
        const br = await b;

        expect(ar.batchId).toBe('first');
        expect(br.batchId).toBe('second');
    });

    it('refuses to start a second concurrent upload', async () => {
        globalThis.fetch = mockFetchSequence([
            batchInitiateResponse(),
            uploadInitiateResponse('u-1', 2, 1024),
            chunkResponse(1, 2),
            chunkResponse(2, 2),
        ]);

        const uploader = new BatchUploader();
        const inFlight = uploader.upload([makeFile('a.bin', 2048)]);

        // Calling upload() again synchronously should reject — only
        // enqueue() is allowed for queued runs.
        await expect(uploader.upload([makeFile('b.bin')])).rejects.toThrow(/already in progress/i);
        await inFlight.catch(() => {});
    });
});

describe('BatchUploader endpoint validation', () => {
    it('initialises with default endpoints', () => {
        expect(() => new BatchUploader()).not.toThrow();
    });

    it('accepts custom endpoints with the {batchId} placeholder', () => {
        expect(
            () => new BatchUploader({
                endpoints: {
                    batchInitiate: '/v2/batch',
                    batchUpload: '/v2/batch/{batchId}/upload',
                    batchStatus: '/v2/batch/{batchId}',
                },
            }),
        ).not.toThrow();
    });
});
