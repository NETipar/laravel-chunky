import { describe, expect, it, vi } from 'vitest';
import { resolveConfig } from './config';
import { fakeServer, makeFile } from './__tests__/support';
import { Sha256 } from './internal/sha256';
import { Uploader } from './Uploader';

function config(fetchImpl: typeof fetch, extra: Record<string, unknown> = {}) {
    return resolveConfig({ fetch: fetchImpl, storage: null, sleep: () => Promise.resolve(), pollIntervalMs: 1, ...extra });
}

function chunkPosts(fetchImpl: typeof fetch): FormData[] {
    return (fetchImpl as unknown as { mock: { calls: [string, RequestInit?][] } }).mock.calls
        .filter(([url, init]) => url.endsWith('/chunks') && init?.method === 'POST')
        .map(([, init]) => init?.body as FormData);
}

function sha256Of(content: string): string {
    return new Sha256().update(new TextEncoder().encode(content)).digestHex();
}

describe('Uploader fileChecksum', () => {
    it('sends the whole-file SHA-256 with the final chunk', async () => {
        const content = 'ABCDEFGHIJ';
        const { fetch } = fakeServer({ chunkSize: 4 });
        const uploader = new Uploader(makeFile(content), { fileChecksum: true }, config(fetch, { concurrency: 1 }));

        const result = await uploader.upload();

        expect(result.status).toBe('completed');
        const posts = chunkPosts(fetch);
        expect(posts).toHaveLength(3);
        expect(posts[posts.length - 1].get('file_checksum')).toBe(sha256Of(content));
    });

    it('delays the final chunk until the hash resolves', async () => {
        const content = 'ABCDEFGHIJ';
        const { fetch } = fakeServer({ chunkSize: 4 });

        // A file whose slices resolve only when released — simulates a hash
        // that is slower than the network.
        let releaseHash!: () => void;
        const gate = new Promise<void>((resolve) => {
            releaseHash = resolve;
        });
        const file = makeFile(content);
        const realSlice = file.slice.bind(file);
        Object.defineProperty(file, 'slice', {
            value: (start?: number, end?: number) => {
                const blob = realSlice(start, end);
                // Only the 64 MB-window hash reads pass through the gate; chunk
                // reads (< file size window) stay untouched. The hash read is
                // the full-file slice (0..size) window.
                if (start === 0 && end === content.length) {
                    const realArrayBuffer = blob.arrayBuffer.bind(blob);
                    Object.defineProperty(blob, 'arrayBuffer', {
                        value: async () => {
                            await gate;
                            return realArrayBuffer();
                        },
                    });
                }
                return blob;
            },
        });

        // resume: false so the fingerprint computation does not also read the
        // gated full-file slice.
        const uploader = new Uploader(file, { fileChecksum: true }, config(fetch, { concurrency: 1, resume: false }));
        const run = uploader.upload();

        // Give the first two chunks time to go out; the final chunk must wait.
        await new Promise((resolve) => setTimeout(resolve, 20));
        expect(chunkPosts(fetch)).toHaveLength(2);

        releaseHash();
        const result = await run;

        expect(result.status).toBe('completed');
        const posts = chunkPosts(fetch);
        expect(posts).toHaveLength(3);
        expect(posts[2].get('file_checksum')).toBe(sha256Of(content));
    });

    it('does not hash or send a checksum by default', async () => {
        const { fetch } = fakeServer({ chunkSize: 4 });
        const uploader = new Uploader(makeFile('ABCDEFGHIJ'), {}, config(fetch));

        await uploader.upload();

        for (const form of chunkPosts(fetch)) {
            expect(form.get('file_checksum')).toBeNull();
        }
    });

    it('surfaces a checksum_mismatch error from the final chunk', async () => {
        const { fetch } = fakeServer({ chunkSize: 4 });
        const rejecting = vi.fn(async (input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
            const url = String(input);
            if (url.endsWith('/chunks') && (init?.body as FormData).get('file_checksum') !== null) {
                return new Response(
                    JSON.stringify({ error: { code: 'checksum_mismatch', message: 'The chunk checksum did not match.' } }),
                    { status: 422, headers: { 'Content-Type': 'application/json' } },
                );
            }
            return fetch(input, init);
        });

        const uploader = new Uploader(
            makeFile('ABCDEFGHIJ'),
            { fileChecksum: true },
            config(rejecting as unknown as typeof fetch, { concurrency: 1, retries: 0 }),
        );

        await expect(uploader.upload()).rejects.toMatchObject({ code: 'checksum_mismatch' });
        expect(uploader.getState().status).toBe('failed');
    });
});
