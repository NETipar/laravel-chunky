import { mount } from '@vue/test-utils';
import { UploadManager } from '@netipar/chunky-core';
import { defineComponent } from 'vue';
import { describe, expect, it, vi } from 'vitest';
import { ChunkyManagerKey } from './manager';
import { useUpload, type UseUpload } from './useUpload';

function jsonResponse(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

/** Initiates normally, then hangs the chunk POST (aborting on signal) so an
 *  upload stays "uploading" while we exercise mount/unmount. */
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
const Harness = defineComponent({
    setup() {
        captured = useUpload();

        return () => null;
    },
});

describe('useUpload', () => {
    it('keeps the upload running after the component unmounts (unmount = unsubscribe)', async () => {
        const manager = testManager();
        const wrapper = mount(Harness, { global: { provide: { [ChunkyManagerKey]: manager } } });

        const uploader = captured.start(new File(['abc'], 'f.bin'));
        await vi.waitFor(() => expect(uploader.getState().status).toBe('uploading'));

        wrapper.unmount();

        expect(manager.uploads()).toContain(uploader);
        expect(uploader.getState().status).not.toBe('cancelled');

        await uploader.cancel();
    });

    it('reflects upload state in a reactive ref', async () => {
        const manager = testManager();
        mount(Harness, { global: { provide: { [ChunkyManagerKey]: manager } } });

        const uploader = captured.start(new File(['abc'], 'f.bin'));
        await vi.waitFor(() => expect(captured.state.value?.status).toBe('uploading'));

        await uploader.cancel();
    });
});
