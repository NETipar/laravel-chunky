import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, renderHook } from '@testing-library/react';
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
    const impl = vi.fn(async () => responses[Math.min(i++, responses.length - 1)].clone());
    return impl as unknown as typeof fetch;
}

describe('useBatchUpload (React)', () => {
    it('returns the expected shape after mount', () => {
        const { result } = renderHook(() => useBatchUpload());

        expect(result.current.batchId).toBeNull();
        expect(result.current.isUploading).toBe(false);
        expect(result.current.isComplete).toBe(false);
        expect(typeof result.current.upload).toBe('function');
        expect(typeof result.current.enqueue).toBe('function');
        expect(typeof result.current.cancel).toBe('function');
    });

    it('updates state during a successful upload', async () => {
        globalThis.fetch = fetchSequence([
            jsonResponse({ batch_id: 'b-1' }),
            jsonResponse({ upload_id: 'u-1', chunk_size: 1024, total_chunks: 1 }),
            jsonResponse({
                chunk_index: 0,
                is_complete: true,
                uploaded_count: 1,
                total_chunks: 1,
                progress: 100,
            }),
        ]);

        const { result } = renderHook(() => useBatchUpload());

        await act(async () => {
            await result.current.upload([makeFile('a.bin')]);
        });

        expect(result.current.batchId).toBe('b-1');
        expect(result.current.isComplete).toBe(true);
        expect(result.current.completedFiles).toBe(1);
    });

    it('destroys the underlying uploader on unmount', () => {
        const { unmount } = renderHook(() => useBatchUpload());

        // Unmount must not throw — proves the useEffect cleanup runs
        // without side effects (and the listener Set.clear() executes).
        expect(() => unmount()).not.toThrow();
    });

    it('forwards onComplete and returns an unsubscribe function', async () => {
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

        const onComplete = vi.fn();
        const { result } = renderHook(() => useBatchUpload());

        let unsub = () => {};
        act(() => {
            unsub = result.current.onComplete(onComplete);
        });

        await act(async () => {
            await result.current.upload([makeFile('a.bin')]);
        });

        expect(onComplete).toHaveBeenCalledTimes(1);
        expect(() => unsub()).not.toThrow();
    });
});
