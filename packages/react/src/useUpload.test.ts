import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, renderHook } from '@testing-library/react';
import { useUpload } from './useUpload';

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

describe('useUpload (React) polymorphic API', () => {
    it('accepts a single File', async () => {
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

        const { result } = renderHook(() => useUpload());

        let res: Awaited<ReturnType<typeof result.current.upload>> | undefined;
        await act(async () => {
            res = await result.current.upload(makeFile('single.bin'));
        });

        expect(res?.totalFiles).toBe(1);
        expect(res?.completedFiles).toBe(1);
    });

    it('accepts a File[] input', async () => {
        globalThis.fetch = fetchSequence([
            jsonResponse({ batch_id: 'b-2' }),
            jsonResponse({ upload_id: 'u-1', chunk_size: 1024, total_chunks: 1 }),
            jsonResponse({
                chunk_index: 0,
                is_complete: true,
                uploaded_count: 1,
                total_chunks: 1,
                progress: 100,
            }),
            jsonResponse({ upload_id: 'u-2', chunk_size: 1024, total_chunks: 1 }),
            jsonResponse({
                chunk_index: 0,
                is_complete: true,
                uploaded_count: 1,
                total_chunks: 1,
                progress: 100,
            }),
        ]);

        const { result } = renderHook(() => useUpload({ maxConcurrentFiles: 1 }));

        let res: Awaited<ReturnType<typeof result.current.upload>> | undefined;
        await act(async () => {
            res = await result.current.upload([makeFile('a.bin'), makeFile('b.bin')]);
        });

        expect(res?.totalFiles).toBe(2);
    });
});
