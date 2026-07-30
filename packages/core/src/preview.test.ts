import { afterEach, describe, expect, it, vi } from 'vitest';
import { resolveConfig } from './config';
import { fakeServer, makeFile } from './__tests__/support';
import { UploadManager } from './UploadManager';
import { Uploader } from './Uploader';
import { createThumbnail } from './thumbnail';

function makeImageFile(name = 'photo.png'): File {
    return new File(['fake-image-bytes'], name, { type: 'image/png' });
}

function config(fetchImpl: typeof fetch) {
    return resolveConfig({ fetch: fetchImpl, storage: null, sleep: () => Promise.resolve(), pollIntervalMs: 1 });
}

describe('Uploader.previewUrl', () => {
    afterEach(() => vi.unstubAllGlobals());

    function stubObjectUrls() {
        const createObjectURL = vi.fn(() => 'blob:preview-1');
        const revokeObjectURL = vi.fn();
        vi.stubGlobal('URL', { createObjectURL, revokeObjectURL });

        return { createObjectURL, revokeObjectURL };
    }

    it('creates the object URL lazily and caches it', () => {
        const { createObjectURL } = stubObjectUrls();
        const { fetch } = fakeServer({ chunkSize: 4 });
        const uploader = new Uploader(makeImageFile(), {}, config(fetch));

        expect(createObjectURL).not.toHaveBeenCalled();
        expect(uploader.previewUrl()).toBe('blob:preview-1');
        expect(uploader.previewUrl()).toBe('blob:preview-1');
        expect(createObjectURL).toHaveBeenCalledTimes(1);
    });

    it('returns null for non-image files', () => {
        const { createObjectURL } = stubObjectUrls();
        const { fetch } = fakeServer({ chunkSize: 4 });
        const uploader = new Uploader(makeFile('ABCD'), {}, config(fetch));

        expect(uploader.previewUrl()).toBeNull();
        expect(createObjectURL).not.toHaveBeenCalled();
    });

    it('returns null without the URL API (SSR guard)', () => {
        vi.stubGlobal('URL', undefined);
        const { fetch } = fakeServer({ chunkSize: 4 });
        const uploader = new Uploader(makeImageFile(), {}, config(fetch));

        expect(uploader.previewUrl()).toBeNull();
    });

    it('revokes once and stays null afterwards', () => {
        const { revokeObjectURL } = stubObjectUrls();
        const { fetch } = fakeServer({ chunkSize: 4 });
        const uploader = new Uploader(makeImageFile(), {}, config(fetch));

        uploader.previewUrl();
        uploader.revokePreview();
        uploader.revokePreview();

        expect(revokeObjectURL).toHaveBeenCalledTimes(1);
        expect(revokeObjectURL).toHaveBeenCalledWith('blob:preview-1');
        expect(uploader.previewUrl()).toBeNull();
    });

    it('does not revoke when no preview was created', () => {
        const { revokeObjectURL } = stubObjectUrls();
        const { fetch } = fakeServer({ chunkSize: 4 });
        const uploader = new Uploader(makeImageFile(), {}, config(fetch));

        uploader.revokePreview();

        expect(revokeObjectURL).not.toHaveBeenCalled();
    });

    it('is revoked by the manager when the upload is evicted', async () => {
        const { revokeObjectURL } = stubObjectUrls();
        const { fetch } = fakeServer({ chunkSize: 4 });
        const manager = new UploadManager({ fetch, storage: null, sleep: () => Promise.resolve(), pollIntervalMs: 1 });

        const uploader = manager.upload(makeImageFile(), {});
        uploader.previewUrl();
        await uploader.upload();

        expect(manager.remove(uploader.id)).toBe(true);
        expect(revokeObjectURL).toHaveBeenCalledWith('blob:preview-1');
    });

    it('does not revoke on terminal states by itself', async () => {
        const { revokeObjectURL } = stubObjectUrls();
        const { fetch } = fakeServer({ chunkSize: 4 });
        const uploader = new Uploader(makeImageFile(), {}, config(fetch));

        uploader.previewUrl();
        await uploader.upload();

        expect(uploader.getState().status).toBe('completed');
        expect(revokeObjectURL).not.toHaveBeenCalled();
    });
});

describe('createThumbnail', () => {
    afterEach(() => vi.unstubAllGlobals());

    function stubBitmapPipeline(width: number, height: number) {
        const close = vi.fn();
        const drawImage = vi.fn();
        const convertToBlob = vi.fn(() => Promise.resolve(new Blob(['thumb'], { type: 'image/webp' })));
        const sizes: { width: number; height: number }[] = [];

        vi.stubGlobal('createImageBitmap', vi.fn(() => Promise.resolve({ width, height, close })));
        vi.stubGlobal('OffscreenCanvas', class {
            constructor(w: number, h: number) {
                sizes.push({ width: w, height: h });
            }

            getContext() {
                return { drawImage };
            }

            convertToBlob = convertToBlob;
        });

        return { close, drawImage, convertToBlob, sizes };
    }

    it('downscales to maxDimension preserving aspect ratio', async () => {
        const { close, convertToBlob, sizes } = stubBitmapPipeline(1024, 512);

        const blob = await createThumbnail(makeImageFile(), { maxDimension: 256 });

        expect(sizes).toEqual([{ width: 256, height: 128 }]);
        expect(convertToBlob).toHaveBeenCalledWith({ type: 'image/webp', quality: 0.8 });
        expect(blob.type).toBe('image/webp');
        expect(close).toHaveBeenCalled();
    });

    it('never upscales small images', async () => {
        const { sizes } = stubBitmapPipeline(100, 50);

        await createThumbnail(makeImageFile(), { maxDimension: 256 });

        expect(sizes).toEqual([{ width: 100, height: 50 }]);
    });

    it('passes type and quality through', async () => {
        const { convertToBlob } = stubBitmapPipeline(512, 512);

        await createThumbnail(makeImageFile(), { type: 'image/jpeg', quality: 0.5 });

        expect(convertToBlob).toHaveBeenCalledWith({ type: 'image/jpeg', quality: 0.5 });
    });

    it('rejects for non-image files', async () => {
        await expect(createThumbnail(makeFile('ABCD'))).rejects.toThrow('requires an image file');
    });
});
