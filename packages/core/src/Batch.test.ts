import { describe, expect, it } from 'vitest';
import { Batch } from './Batch';
import { resolveConfig } from './config';
import { fakeServer, makeFile } from './__tests__/support';

function config(fetchImpl: typeof fetch) {
    return resolveConfig({ fetch: fetchImpl, storage: null, sleep: () => Promise.resolve(), pollIntervalMs: 1 });
}

describe('Batch', () => {
    it('uploads every member and completes the batch', async () => {
        const { fetch } = fakeServer({ chunkSize: 4 });
        const batch = new Batch([makeFile('ABCDEFGHIJ'), makeFile('KLMNOPQRST')], {}, config(fetch));

        const result = await batch.upload();

        expect(result.status).toBe('completed');
        expect(result.results).toHaveLength(2);
        expect(result.results.every((r) => r.status === 'completed')).toBe(true);
        expect(batch.getState().completed).toBe(2);
        expect(batch.getState().total).toBe(2);
    });

    it('aggregates progress across members', async () => {
        const { fetch } = fakeServer({ chunkSize: 4 });
        const batch = new Batch([makeFile('ABCDEFGHIJ')], {}, config(fetch));

        await batch.upload();

        expect(batch.getState().progress).toBe(100);
    });
});
