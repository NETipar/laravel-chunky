import { describe, expect, it, vi } from 'vitest';
import { resolveConfig } from './config';
import { jsonResponse, makeFile } from './__tests__/support';
import { Uploader } from './Uploader';

interface DirectFakeOptions {
    chunkSize: number;
    totalChunks: number;
    initialUrlCount?: number;
    resumedParts?: Record<string, string>;
    expireFirstPutFor?: number[];
    omitEtag?: boolean;
}

/**
 * Fake of the direct_s3 protocol: initiate hands out presigned URLs, S3 PUTs
 * return ETags, part-urls refills, complete validates the collected parts.
 */
function directFakeServer(options: DirectFakeOptions) {
    const urlFor = (index: number, generation: number): string =>
        `https://s3.test/bucket/key?partNumber=${index + 1}&gen=${generation}`;

    let urlGeneration = 0;
    const expired = new Set(options.expireFirstPutFor ?? []);
    const putParts: number[] = [];
    let completeBody: unknown = null;
    let partUrlCalls = 0;

    const initialUrls: Record<string, string> = {};
    const resumedIndexes = Object.keys(options.resumedParts ?? {}).map(Number);
    const missing = Array.from({ length: options.totalChunks }, (_, i) => i).filter((i) => !resumedIndexes.includes(i));
    for (const index of missing.slice(0, options.initialUrlCount ?? options.totalChunks)) {
        initialUrls[String(index)] = urlFor(index, 0);
    }

    const fetchImpl = vi.fn(async (input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
        const url = String(input);
        const method = init?.method ?? 'GET';

        if (url.endsWith('/upload') && method === 'POST') {
            return jsonResponse({
                upload_id: 'u1',
                chunk_size: options.chunkSize,
                total_chunks: options.totalChunks,
                resumed: resumedIndexes.length > 0,
                uploaded_chunks: resumedIndexes,
                transport: {
                    mode: 'direct_s3',
                    part_urls: initialUrls,
                    expires_at: '2026-07-30T12:00:00Z',
                    ...(options.resumedParts ? { uploaded_parts: options.resumedParts } : {}),
                },
            }, resumedIndexes.length > 0 ? 200 : 201);
        }

        if (url.endsWith('/part-urls') && method === 'POST') {
            partUrlCalls++;
            urlGeneration++;
            const body = JSON.parse(String(init?.body)) as { indexes: number[] };
            const partUrls: Record<string, string> = {};
            for (const index of body.indexes) {
                partUrls[String(index)] = urlFor(index, urlGeneration);
            }

            return jsonResponse({ part_urls: partUrls, expires_at: '2026-07-30T13:00:00Z' });
        }

        if (url.startsWith('https://s3.test/') && method === 'PUT') {
            const parsed = new URL(url);
            const index = Number(parsed.searchParams.get('partNumber')) - 1;
            const generation = Number(parsed.searchParams.get('gen'));

            if (expired.has(index) && generation === 0) {
                expired.delete(index);

                return new Response('<Error>expired</Error>', { status: 403 });
            }

            putParts.push(index);
            const headers: Record<string, string> = options.omitEtag ? {} : { ETag: `"etag-${index}"` };

            return new Response(null, { status: 200, headers });
        }

        if (url.endsWith('/complete') && method === 'POST') {
            completeBody = JSON.parse(String(init?.body));

            return jsonResponse({
                status: 'completed',
                progress: 100,
                file: { path: 'direct-files/f.bin', size: options.chunkSize * options.totalChunks },
                payload: { media_id: 42 },
            });
        }

        return jsonResponse({ error: { code: 'not_found', message: 'x' } }, 404);
    });

    return {
        fetch: fetchImpl as unknown as typeof fetch,
        putParts,
        getCompleteBody: () => completeBody,
        getPartUrlCalls: () => partUrlCalls,
    };
}

function config(fetchImpl: typeof fetch) {
    return resolveConfig({
        fetch: fetchImpl,
        storage: null,
        sleep: () => Promise.resolve(),
        pollIntervalMs: 1,
        concurrency: 1,
        resume: false,
    });
}

describe('Uploader direct_s3 transport', () => {
    it('PUTs parts to S3 and completes with the collected ETags — same caller API', async () => {
        const server = directFakeServer({ chunkSize: 4, totalChunks: 3 });
        const uploader = new Uploader(makeFile('ABCDEFGHIJKL'), {}, config(server.fetch));

        const result = await uploader.upload();

        expect(result.status).toBe('completed');
        expect(result.file?.path).toBe('direct-files/f.bin');
        expect(result.payload).toEqual({ media_id: 42 });
        expect(server.putParts).toEqual([0, 1, 2]);
        expect(server.getCompleteBody()).toEqual({
            parts: [
                { index: 0, etag: '"etag-0"' },
                { index: 1, etag: '"etag-1"' },
                { index: 2, etag: '"etag-2"' },
            ],
        });
        expect(uploader.getState().status).toBe('completed');
        expect(uploader.getState().progress).toBe(100);
    });

    it('refills the URL pool from the part-urls endpoint', async () => {
        const server = directFakeServer({ chunkSize: 4, totalChunks: 3, initialUrlCount: 1 });
        const uploader = new Uploader(makeFile('ABCDEFGHIJKL'), {}, config(server.fetch));

        await uploader.upload();

        expect(server.putParts).toEqual([0, 1, 2]);
        expect(server.getPartUrlCalls()).toBeGreaterThanOrEqual(1);
    });

    it('retries an expired presigned URL with a fresh one', async () => {
        const server = directFakeServer({ chunkSize: 4, totalChunks: 2, expireFirstPutFor: [1] });
        const uploader = new Uploader(makeFile('ABCDEFGH'), {}, config(server.fetch));

        const result = await uploader.upload();

        expect(result.status).toBe('completed');
        expect(server.putParts).toEqual([0, 1]);
        expect(server.getPartUrlCalls()).toBe(1);
    });

    it('fails with a CORS hint when S3 does not expose the ETag', async () => {
        const server = directFakeServer({ chunkSize: 4, totalChunks: 1, omitEtag: true });
        const uploader = new Uploader(makeFile('ABCD'), {}, config(server.fetch));

        await expect(uploader.upload()).rejects.toMatchObject({ code: 'missing_etag' });
        expect(uploader.getState().status).toBe('failed');
    });

    it('resumes with server-provided ETags for already-uploaded parts', async () => {
        const server = directFakeServer({
            chunkSize: 4,
            totalChunks: 3,
            resumedParts: { '0': '"etag-0"' },
        });
        const uploader = new Uploader(makeFile('ABCDEFGHIJKL'), {}, config(server.fetch));

        const result = await uploader.upload();

        expect(result.status).toBe('completed');
        expect(server.putParts).toEqual([1, 2]);
        expect(server.getCompleteBody()).toEqual({
            parts: [
                { index: 0, etag: '"etag-0"' },
                { index: 1, etag: '"etag-1"' },
                { index: 2, etag: '"etag-2"' },
            ],
        });
    });
});
