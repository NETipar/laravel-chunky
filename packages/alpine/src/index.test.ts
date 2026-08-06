import { describe, expect, it, vi } from 'vitest';
import { type AlpineLike, type ChunkyUploadComponent, registerChunky } from './index';

function jsonResponse(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

function hangingServer(): typeof fetch {
    return (async (input: RequestInfo | URL, init?: RequestInit) => {
        const url = String(input);
        const method = init?.method ?? 'GET';

        if (url.endsWith('/upload') && method === 'POST') {
            return jsonResponse({ upload_id: 'u1', chunk_size: 100, total_chunks: 1, resumed: false, uploaded_chunks: [] }, 201);
        }

        if (/\/chunks$/.test(url)) {
            return new Promise<Response>((_, reject) => {
                init?.signal?.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')));
            });
        }

        return jsonResponse({ status: 'assembling', progress: 100 });
    }) as typeof fetch;
}

describe('registerChunky', () => {
    it('registers a chunkyUpload component that starts a manager-owned upload', async () => {
        let factory: ((...args: never[]) => object) | null = null;
        const alpine: AlpineLike = {
            data: (_name, providedFactory) => {
                factory = providedFactory;
            },
        };

        const manager = registerChunky(alpine, { fetch: hangingServer(), storage: null, sleep: () => Promise.resolve() });

        expect(factory).not.toBeNull();
        const component = (factory as unknown as (...args: never[]) => object)() as ChunkyUploadComponent;

        component.start(new File(['abc'], 'f.bin'));
        await vi.waitFor(() => expect(component.state?.status).toBe('uploading'));

        expect(manager.uploads()).toContain(component.uploader);

        await component.uploader?.cancel();
    });
});
