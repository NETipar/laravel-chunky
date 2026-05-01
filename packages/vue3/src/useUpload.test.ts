import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope } from 'vue';
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

describe('useUpload polymorphic API', () => {
    it('accepts a single File as input', async () => {
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

        const scope = effectScope();
        const u = scope.run(() => useUpload())!;

        const result = await u.upload(makeFile('single.bin'));
        expect(result.totalFiles).toBe(1);
        expect(result.completedFiles).toBe(1);

        scope.stop();
    });

    it('accepts an array of Files as input', async () => {
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

        const scope = effectScope();
        const u = scope.run(() => useUpload({ maxConcurrentFiles: 1 }))!;

        const result = await u.upload([makeFile('a.bin'), makeFile('b.bin')]);
        expect(result.totalFiles).toBe(2);

        scope.stop();
    });

    it('enqueue() wraps a single File in an array', async () => {
        globalThis.fetch = fetchSequence([
            jsonResponse({ batch_id: 'b-3' }),
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
        const u = scope.run(() => useUpload())!;

        const result = await u.enqueue(makeFile('single.bin'));
        expect(result.totalFiles).toBe(1);

        scope.stop();
    });
});
