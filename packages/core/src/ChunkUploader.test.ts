import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ChunkUploader } from './ChunkUploader';
import { mockFetchSequence, makeFile } from './__tests__/helpers';

describe('ChunkUploader', () => {
    let originalFetch: typeof fetch;

    beforeEach(() => {
        originalFetch = globalThis.fetch;
    });

    afterEach(() => {
        globalThis.fetch = originalFetch;
        vi.useRealTimers();
    });

    it('initiates an upload and POSTs each chunk with an Idempotency-Key header', async () => {
        // 2-chunk upload: initiate + 2 chunk POSTs (last one is_complete).
        const fetch = mockFetchSequence([
            { status: 201, json: { upload_id: 'u-1', chunk_size: 1024, total_chunks: 2 } },
            { status: 200, json: { chunk_index: 0, is_complete: false, uploaded_count: 1, total_chunks: 2, progress: 50 } },
            { status: 200, json: { chunk_index: 1, is_complete: true, uploaded_count: 2, total_chunks: 2, progress: 100 } },
        ]);
        globalThis.fetch = fetch as unknown as typeof globalThis.fetch;

        const uploader = new ChunkUploader({ checksum: false, autoRetry: false });
        const result = await uploader.upload(makeFile('a.bin', 2 * 1024));

        expect(result.uploadId).toBe('u-1');
        expect(result.totalChunks).toBe(2);

        // Each chunk POST should have an Idempotency-Key shaped (uploadId:chunkIndex).
        const chunkCalls = fetch.mock.calls.filter(
            (c: unknown[]) => (c[1] as RequestInit).method === 'POST' && (c[0] as string).includes('/chunks'),
        );
        expect(chunkCalls.length).toBe(2);
        for (let i = 0; i < chunkCalls.length; i++) {
            const init = chunkCalls[i][1] as RequestInit;
            const headers = init.headers as Record<string, string>;
            expect(headers['Idempotency-Key']).toBe(`u-1:${i}`);
        }
    });

    it('refuses to proceed when the server returns no chunk_size and no override is set', async () => {
        globalThis.fetch = mockFetchSequence([
            { status: 201, json: { upload_id: 'u-2', chunk_size: 0, total_chunks: 2 } },
        ]) as unknown as typeof globalThis.fetch;

        const uploader = new ChunkUploader({ checksum: false, autoRetry: false });

        await expect(uploader.upload(makeFile('b.bin'))).rejects.toThrow(
            /chunk_size/,
        );
    });

    it('does not reuse a stale uploadId when called with a different File', async () => {
        const fetch = mockFetchSequence([
            // First file fails on chunk 0.
            { status: 201, json: { upload_id: 'u-stale', chunk_size: 1024, total_chunks: 1 } },
            { status: 500, json: { message: 'oops' } },
            // Second file should trigger a fresh initiate, not resume against u-stale.
            { status: 201, json: { upload_id: 'u-new', chunk_size: 1024, total_chunks: 1 } },
            { status: 200, json: { chunk_index: 0, is_complete: true, uploaded_count: 1, total_chunks: 1, progress: 100 } },
        ]);
        globalThis.fetch = fetch as unknown as typeof globalThis.fetch;

        const uploader = new ChunkUploader({ checksum: false, autoRetry: false });

        await expect(uploader.upload(makeFile('first.bin'))).rejects.toBeDefined();

        // A different File reference must not resume against the stale upload ID.
        const result = await uploader.upload(makeFile('second.bin'));
        expect(result.uploadId).toBe('u-new');

        // First call after the first failure should be a fresh POST /upload (init), not GET /upload/u-stale.
        const calls = fetch.mock.calls;
        const firstAfterFailure = calls[2];
        expect((firstAfterFailure[1] as RequestInit).method).toBe('POST');
        expect(firstAfterFailure[0] as string).toMatch(/\/upload$/);
    });

    it('cancel() aborts the in-flight chunk and triggers a DELETE on the server', async () => {
        const fetch = vi.fn();
        // initiate
        fetch.mockResolvedValueOnce(
            new Response(JSON.stringify({ upload_id: 'u-3', chunk_size: 1024, total_chunks: 1 }), {
                status: 201,
                headers: { 'Content-Type': 'application/json' },
            }),
        );
        // chunk POST hangs forever — we cancel before it resolves
        let chunkResolved = false;
        fetch.mockImplementationOnce((_url, init) => {
            return new Promise((resolve, reject) => {
                (init.signal as AbortSignal).addEventListener('abort', () => {
                    reject(new DOMException('aborted', 'AbortError'));
                });
            });
        });
        // The DELETE fired by cancelOnServer
        fetch.mockResolvedValueOnce(new Response(null, { status: 204 }));
        globalThis.fetch = fetch as unknown as typeof globalThis.fetch;

        const uploader = new ChunkUploader({ checksum: false, autoRetry: false });
        const uploadPromise = uploader.upload(makeFile('c.bin'));

        // Wait briefly so the chunk POST is in flight.
        await new Promise((r) => setTimeout(r, 5));
        uploader.cancel();

        await expect(uploadPromise).rejects.toBeDefined();
        expect(chunkResolved).toBe(false);

        // Eventually a DELETE was fired against the cancel endpoint.
        await new Promise((r) => setTimeout(r, 5));
        const deleteCall = fetch.mock.calls.find((c: unknown[]) => (c[1] as RequestInit).method === 'DELETE');
        expect(deleteCall).toBeDefined();
    });
});
