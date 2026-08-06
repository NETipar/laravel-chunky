import { describe, expect, it, vi } from 'vitest';
import { type ChunkyConfig, resolveConfig } from './config';
import { fakeServer, jsonResponse, makeFile } from './__tests__/support';
import { Uploader } from './Uploader';

function config(fetchImpl: typeof fetch, extra: ChunkyConfig = {}) {
    return resolveConfig({ fetch: fetchImpl, storage: null, sleep: () => Promise.resolve(), pollIntervalMs: 1, ...extra });
}

describe('Uploader', () => {
    it('completes a sync upload and resolves with the result', async () => {
        const { fetch } = fakeServer({ chunkSize: 4 });
        const uploader = new Uploader(makeFile('ABCDEFGHIJ'), {}, config(fetch));

        const result = await uploader.upload();

        expect(result.status).toBe('completed');
        expect(result.file).toEqual({ path: 'final/f.bin', size: 10 });
        expect(result.payload).toEqual({ media_id: 7 });
        expect(uploader.getState().status).toBe('completed');
        expect(uploader.getState().progress).toBe(100);
    });

    it('polls to completion in queue mode', async () => {
        const { fetch } = fakeServer({ chunkSize: 4, mode: 'queue' });

        const result = await new Uploader(makeFile('ABCDEFGHIJ'), {}, config(fetch)).upload();

        expect(result.status).toBe('completed');
    });

    it('resumes from server-reported uploaded chunks', async () => {
        const { fetch } = fakeServer({ chunkSize: 4, resumedChunks: [0] });
        await new Uploader(makeFile('ABCDEFGHIJ'), {}, config(fetch)).upload();

        const chunkPosts = (fetch as unknown as { mock: { calls: [string, RequestInit?][] } }).mock.calls
            .filter(([url, init]) => url.endsWith('/chunks') && init?.method === 'POST');

        expect(chunkPosts).toHaveLength(2);
    });

    it('notifies subscribers with an immutable snapshot', async () => {
        const { fetch } = fakeServer({ chunkSize: 4 });
        const uploader = new Uploader(makeFile('ABCDEFGHIJ'), {}, config(fetch));
        const statuses: string[] = [];
        uploader.subscribe((state) => statuses.push(state.status));

        await uploader.upload();

        expect(statuses[0]).toBe('idle');
        expect(statuses).toContain('completed');
        expect(uploader.getState().progress).toBe(100);
    });

    it('rejects and marks failed on a fatal error', async () => {
        const fetchImpl = vi.fn().mockResolvedValue(jsonResponse({ error: { code: 'unauthorized', message: 'no' } }, 403));
        const uploader = new Uploader(makeFile('AB'), {}, config(fetchImpl as unknown as typeof fetch));

        await expect(uploader.upload()).rejects.toMatchObject({ code: 'unauthorized' });
        expect(uploader.getState().status).toBe('failed');
    });

    it('cancels and reports a cancelled state', async () => {
        const { fetch } = fakeServer({ chunkSize: 4 });
        const uploader = new Uploader(makeFile('ABCDEFGHIJ'), {}, config(fetch));
        const cancelled = vi.fn();
        uploader.on('cancelled', cancelled);

        await uploader.cancel();

        expect(uploader.getState().status).toBe('cancelled');
        expect(cancelled).toHaveBeenCalled();
    });

    it('replays the completed event to a late subscriber (sticky)', async () => {
        const { fetch } = fakeServer({ chunkSize: 4 });
        const uploader = new Uploader(makeFile('ABCDEFGHIJ'), {}, config(fetch));
        await uploader.upload();

        const completed = vi.fn();
        uploader.on('completed', completed);

        expect(completed).toHaveBeenCalledTimes(1);
    });

    it('pauses between chunks and resumes to completion', async () => {
        const gates: Array<() => void> = [];
        const base = fakeServer({ chunkSize: 4 });
        const controlled = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            if (String(input).endsWith('/chunks') && init?.method === 'POST') {
                await new Promise<void>((resolve) => gates.push(resolve));
            }

            return base.fetch(input, init);
        });

        const uploader = new Uploader(
            makeFile('ABCDEFGHIJ'),
            {},
            config(controlled as unknown as typeof fetch, { concurrency: 1 }),
        );
        const promise = uploader.upload();

        await vi.waitFor(() => expect(gates).toHaveLength(1));
        uploader.pause();
        gates[0]();

        await vi.waitFor(() => expect(uploader.getState().status).toBe('paused'));
        expect(uploader.resume()).toBe(true);

        await vi.waitFor(() => expect(gates.length).toBeGreaterThanOrEqual(2));
        gates[1]();
        await vi.waitFor(() => expect(gates).toHaveLength(3));
        gates[2]();

        const result = await promise;
        expect(result.status).toBe('completed');
    });
});
