import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { registerChunkUpload, type AlpineChunkUploadData, type AlpineLike } from './chunk-upload';

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

/**
 * Build a fake Alpine instance that captures the registered factory and
 * lets a test instantiate the resulting component data with a `$dispatch`
 * spy bound to it.
 */
function fakeAlpine() {
    let registeredName: string | null = null;
    let factory: ((...args: unknown[]) => AlpineChunkUploadData) | null = null;

    const Alpine: AlpineLike = {
        data(name, fn) {
            registeredName = name;
            factory = fn as (...args: unknown[]) => AlpineChunkUploadData;
        },
    };

    return {
        Alpine,
        getRegisteredName: () => registeredName,
        instantiate(...args: unknown[]) {
            if (!factory) throw new Error('Alpine.data was never called.');
            const dispatch = vi.fn();
            const data = factory(...args);
            // Bind a $dispatch spy onto `this` for the data object.
            const bound = Object.assign(data, { $dispatch: dispatch });
            return { data: bound, dispatch };
        },
    };
}

describe('registerChunkUpload Alpine factory', () => {
    it('registers under the "chunkUpload" key', () => {
        const a = fakeAlpine();
        registerChunkUpload(a.Alpine);

        expect(a.getRegisteredName()).toBe('chunkUpload');
    });

    it('exposes the documented data shape before init()', () => {
        const a = fakeAlpine();
        registerChunkUpload(a.Alpine);

        const { data } = a.instantiate();
        expect(data.progress).toBe(0);
        expect(data.isUploading).toBe(false);
        expect(data.isComplete).toBe(false);
        expect(data.uploadId).toBeNull();
        expect(typeof data.upload).toBe('function');
        expect(typeof data.cancel).toBe('function');
    });

    it('init() instantiates the inner ChunkUploader', () => {
        const a = fakeAlpine();
        registerChunkUpload(a.Alpine);

        const { data } = a.instantiate();
        expect(data._uploader).toBeNull();

        data.init();
        expect(data._uploader).not.toBeNull();

        data.destroy();
    });

    it('dispatches Alpine custom events on lifecycle hooks', async () => {
        globalThis.fetch = fetchSequence([
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
        registerChunkUpload(a.Alpine);
        const { data, dispatch } = a.instantiate();
        data.init();

        await data.upload(makeFile('a.bin'));

        // chunky:progress, chunky:chunk-uploaded, chunky:complete should fire.
        const events = dispatch.mock.calls.map((c) => c[0] as string);
        expect(events).toContain('chunky:progress');
        expect(events).toContain('chunky:chunk-uploaded');
        expect(events).toContain('chunky:complete');

        data.destroy();
    });

    it('handleFileInput triggers an upload when a file is selected', () => {
        const a = fakeAlpine();
        registerChunkUpload(a.Alpine);
        const { data } = a.instantiate();
        data.init();

        const uploadSpy = vi.spyOn(data, 'upload').mockResolvedValue({
            uploadId: 'mocked',
            fileName: 'x.bin',
            fileSize: 1024,
            totalChunks: 1,
        });

        const fakeEvent = {
            target: {
                files: [makeFile('x.bin')],
            },
        } as unknown as Event;

        data.handleFileInput(fakeEvent);

        expect(uploadSpy).toHaveBeenCalledTimes(1);
        data.destroy();
    });
});
