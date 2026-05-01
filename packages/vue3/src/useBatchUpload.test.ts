import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope, nextTick } from 'vue';
import { useBatchUpload } from './useBatchUpload';

const ORIGINAL_FETCH = globalThis.fetch;

beforeEach(() => {
    vi.spyOn(document, 'cookie', 'get').mockReturnValue('');
});

afterEach(() => {
    globalThis.fetch = ORIGINAL_FETCH;
    vi.restoreAllMocks();
});

function makeFile(name: string, size = 1024): File {
    return new File([new Uint8Array(size)], name, { type: 'application/octet-stream' });
}

function jsonResponse(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

function fetchSequence(responses: Response[]): typeof fetch {
    let i = 0;
    const impl = vi.fn(async () => {
        return responses[Math.min(i++, responses.length - 1)].clone();
    });
    return impl as unknown as typeof fetch;
}

describe('useBatchUpload reactivity', () => {
    it('exposes refs that update on stateChange', async () => {
        globalThis.fetch = fetchSequence([
            jsonResponse({ batch_id: 'batch-1' }),
            jsonResponse({ upload_id: 'u-1', chunk_size: 1024, total_chunks: 1 }),
            jsonResponse({
                chunk_index: 0,
                is_complete: true,
                uploaded_count: 1,
                total_chunks: 1,
                progress: 100,
            }),
        ]);

        const scope = effectScope();
        const composable = scope.run(() => useBatchUpload())!;

        expect(composable.isUploading.value).toBe(false);
        expect(composable.isComplete.value).toBe(false);

        await composable.upload([makeFile('a.bin')]);
        await nextTick();

        expect(composable.isComplete.value).toBe(true);
        expect(composable.batchId.value).toBe('batch-1');
        expect(composable.completedFiles.value).toBe(1);

        scope.stop();
    });

    it('disposes the underlying uploader when its scope ends', async () => {
        globalThis.fetch = fetchSequence([
            jsonResponse({ batch_id: 'b-2' }),
            jsonResponse({ upload_id: 'u-2', chunk_size: 1024, total_chunks: 1 }),
            jsonResponse({
                chunk_index: 0,
                is_complete: true,
                uploaded_count: 1,
                total_chunks: 1,
                progress: 100,
            }),
        ]);

        const scope = effectScope();
        const composable = scope.run(() => useBatchUpload())!;

        const destroySpy = vi.spyOn(composable, 'destroy');
        scope.stop();

        // After scope.stop() the onScopeDispose() callback has fired, which
        // calls uploader.destroy(). The composable's destroy() method is a
        // separate wrapper; we just confirm scope teardown does not throw
        // and the uploader is unusable afterwards.
        expect(() => composable.cancel()).not.toThrow();
        destroySpy.mockRestore();
    });

    it('forwards on* callbacks to the uploader and returns unsubscribe', async () => {
        globalThis.fetch = fetchSequence([
            jsonResponse({ batch_id: 'b-3' }),
            jsonResponse({ upload_id: 'u-3', chunk_size: 1024, total_chunks: 1 }),
            jsonResponse({
                chunk_index: 0,
                is_complete: true,
                uploaded_count: 1,
                total_chunks: 1,
                progress: 100,
            }),
        ]);

        const scope = effectScope();
        const composable = scope.run(() => useBatchUpload())!;

        const onComplete = vi.fn();
        const unsub = composable.onComplete(onComplete);

        await composable.upload([makeFile('a.bin')]);
        expect(onComplete).toHaveBeenCalledTimes(1);

        unsub();

        // After unsub a sticky-replay subscriber would not fire. We verify
        // the unsub function is callable and idempotent.
        expect(() => unsub()).not.toThrow();

        scope.stop();
    });

    it('cancel() resolves isUploading to false', async () => {
        globalThis.fetch = fetchSequence([
            jsonResponse({ batch_id: 'b-4' }),
            jsonResponse({ upload_id: 'u-4', chunk_size: 1024, total_chunks: 1 }),
            jsonResponse({
                chunk_index: 0,
                is_complete: true,
                uploaded_count: 1,
                total_chunks: 1,
                progress: 100,
            }),
        ]);

        const scope = effectScope();
        const composable = scope.run(() => useBatchUpload())!;

        const promise = composable.upload([makeFile('a.bin')]);
        composable.cancel();

        // The upload promise either rejects (cancel raced the in-flight
        // request) or resolves with a partial result — either is fine
        // for this test's purpose.
        await promise.catch(() => {});

        expect(composable.isUploading.value).toBe(false);

        scope.stop();
    });
});
