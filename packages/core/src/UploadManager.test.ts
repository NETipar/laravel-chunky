import { describe, expect, it, vi } from 'vitest';
import { fakeServer, makeFile } from './__tests__/support';
import { UploadManager } from './UploadManager';

function manager(fetchImpl: typeof fetch): UploadManager {
    return new UploadManager({ fetch: fetchImpl, storage: null, sleep: () => Promise.resolve(), pollIntervalMs: 1 });
}

describe('UploadManager', () => {
    it('owns the upload so it survives losing the caller reference', async () => {
        const { fetch } = fakeServer({ chunkSize: 4 });
        const mgr = manager(fetch);

        const uploader = mgr.upload(makeFile('ABCDEFGHIJ'));
        await vi.waitFor(() => expect(uploader.getState().status).toBe('completed'));

        expect(mgr.uploads()).toContain(uploader);
    });

    it('notifies subscribers when uploads change', async () => {
        const { fetch } = fakeServer({ chunkSize: 4 });
        const mgr = manager(fetch);
        const changes = vi.fn();
        mgr.subscribe(changes);

        const uploader = mgr.upload(makeFile('ABCDEFGHIJ'));
        await vi.waitFor(() => expect(uploader.getState().status).toBe('completed'));

        expect(changes).toHaveBeenCalled();
    });

    it('removes a terminal upload but not an unknown one', async () => {
        const { fetch } = fakeServer({ chunkSize: 4 });
        const mgr = manager(fetch);
        const uploader = mgr.upload(makeFile('ABCDEFGHIJ'));
        await vi.waitFor(() => expect(uploader.getState().status).toBe('completed'));

        expect(mgr.remove('nope')).toBe(false);
        expect(mgr.remove(uploader.id)).toBe(true);
        expect(mgr.uploads()).toHaveLength(0);
    });

    it('installs and removes a page-unload guard', () => {
        const target = new EventTarget();
        const addSpy = vi.spyOn(target, 'addEventListener');
        const removeSpy = vi.spyOn(target, 'removeEventListener');
        const mgr = manager(fakeServer({ chunkSize: 4 }).fetch);

        const off = mgr.installUnloadGuard(target);
        expect(addSpy).toHaveBeenCalledWith('beforeunload', expect.any(Function));

        off();
        expect(removeSpy).toHaveBeenCalledWith('beforeunload', expect.any(Function));
    });
});
