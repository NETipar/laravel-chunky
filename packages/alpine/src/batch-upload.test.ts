import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { registerBatchUpload, type AlpineBatchUploadData } from './batch-upload';
import type { AlpineLike } from './chunk-upload';

const ORIGINAL_FETCH = globalThis.fetch;

beforeEach(() => {
    vi.spyOn(document, 'cookie', 'get').mockReturnValue('');
});

afterEach(() => {
    globalThis.fetch = ORIGINAL_FETCH;
    vi.restoreAllMocks();
});

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

function makeFile(name: string, size = 1024): File {
    return new File([new Uint8Array(size)], name, { type: 'application/octet-stream' });
}

function fakeAlpine() {
    let registeredName: string | null = null;
    let factory: ((...args: unknown[]) => AlpineBatchUploadData) | null = null;

    const Alpine: AlpineLike = {
        data(name, fn) {
            registeredName = name;
            factory = fn as (...args: unknown[]) => AlpineBatchUploadData;
        },
    };

    return {
        Alpine,
        getRegisteredName: () => registeredName,
        instantiate(...args: unknown[]) {
            if (!factory) throw new Error('Alpine.data was never called.');
            const dispatch = vi.fn();
            const data = factory(...args);
            const bound = Object.assign(data, { $dispatch: dispatch });
            return { data: bound, dispatch };
        },
    };
}

describe('registerBatchUpload Alpine factory', () => {
    it('registers under the "batchUpload" key', () => {
        const a = fakeAlpine();
        registerBatchUpload(a.Alpine);

        expect(a.getRegisteredName()).toBe('batchUpload');
    });

    it('exposes the documented data shape before init()', () => {
        const a = fakeAlpine();
        registerBatchUpload(a.Alpine);

        const { data } = a.instantiate();
        expect(data.batchId).toBeNull();
        expect(data.totalFiles).toBe(0);
        expect(data.completedFiles).toBe(0);
        expect(data.failedFiles).toBe(0);
        expect(data.progress).toBe(0);
        expect(data.isUploading).toBe(false);
        expect(data.isComplete).toBe(false);
        expect(typeof data.upload).toBe('function');
    });

    it('dispatches batch-* events on a successful single-file batch', async () => {
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

        const a = fakeAlpine();
        registerBatchUpload(a.Alpine);
        const { data, dispatch } = a.instantiate();
        data.init();

        await data.upload([makeFile('a.bin')]);

        const events = dispatch.mock.calls.map((c) => c[0] as string);
        expect(events).toContain('chunky:batch-progress');
        expect(events).toContain('chunky:batch-complete');

        data.destroy();
    });

    it('handleFileInput uploads multiple files', () => {
        const a = fakeAlpine();
        registerBatchUpload(a.Alpine);
        const { data } = a.instantiate();
        data.init();

        const uploadSpy = vi.spyOn(data, 'upload').mockResolvedValue({
            batchId: 'mock',
            totalFiles: 2,
            completedFiles: 2,
            failedFiles: 0,
            files: [],
        });

        const fakeEvent = {
            target: {
                files: [makeFile('a.bin'), makeFile('b.bin')],
            },
        } as unknown as Event;

        data.handleFileInput(fakeEvent);

        expect(uploadSpy).toHaveBeenCalledWith(expect.arrayContaining([
            expect.objectContaining({ name: 'a.bin' }),
            expect.objectContaining({ name: 'b.bin' }),
        ]));

        data.destroy();
    });
});
