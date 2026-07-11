import { act, cleanup, render, waitFor } from '@testing-library/react';
import { UploadManager } from '@netipar/chunky-core';
import { afterEach, describe, expect, it } from 'vitest';
import { ChunkyProvider } from './ChunkyProvider';
import { useUpload, type UseUpload } from './useUpload';

afterEach(cleanup);

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

function testManager(): UploadManager {
    return new UploadManager({ fetch: hangingServer(), storage: null, sleep: () => Promise.resolve() });
}

let captured: UseUpload;
function Harness(): null {
    captured = useUpload();

    return null;
}

describe('useUpload', () => {
    it('keeps the upload running after the component unmounts', async () => {
        const manager = testManager();
        const { unmount } = render(
            <ChunkyProvider manager={manager}>
                <Harness />
            </ChunkyProvider>,
        );

        let uploader!: ReturnType<UseUpload['start']>;
        act(() => {
            uploader = captured.start(new File(['abc'], 'f.bin'));
        });

        await waitFor(() => expect(uploader.getState().status).toBe('uploading'));

        unmount();

        expect(manager.uploads()).toContain(uploader);
        expect(uploader.getState().status).not.toBe('cancelled');

        await uploader.cancel();
    });
});
