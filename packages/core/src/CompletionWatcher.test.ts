import { describe, expect, it, vi } from 'vitest';
import { CompletionWatcher } from './CompletionWatcher';
import { resolveConfig } from './config';
import { jsonResponse } from './__tests__/support';

function config(fetchImpl: typeof fetch) {
    return resolveConfig({ fetch: fetchImpl, storage: null, sleep: () => Promise.resolve(), pollIntervalMs: 1 });
}

describe('CompletionWatcher', () => {
    it('polls until the upload completes', async () => {
        let calls = 0;
        const fetchImpl = vi.fn(async () => {
            calls++;

            return calls < 3
                ? jsonResponse({ status: 'assembling', progress: 50 })
                : jsonResponse({ status: 'completed', progress: 100, file: { path: 'p', size: 1 }, payload: { id: 9 } });
        });

        const result = await new CompletionWatcher('u1', config(fetchImpl as unknown as typeof fetch)).wait();

        expect(result.status).toBe('completed');
        expect(result.file).toEqual({ path: 'p', size: 1 });
        expect(calls).toBe(3);
    });

    it('resolves a failed assembly', async () => {
        const fetchImpl = vi.fn().mockResolvedValue(jsonResponse({ status: 'failed', progress: 100 }));

        const result = await new CompletionWatcher('u1', config(fetchImpl as unknown as typeof fetch)).wait();

        expect(result.status).toBe('failed');
    });

    it('throws when stopped', async () => {
        const fetchImpl = vi.fn().mockResolvedValue(jsonResponse({ status: 'assembling', progress: 10 }));
        const watcher = new CompletionWatcher('u1', config(fetchImpl as unknown as typeof fetch));
        watcher.stop();

        await expect(watcher.wait()).rejects.toMatchObject({ code: 'cancelled' });
    });
});
