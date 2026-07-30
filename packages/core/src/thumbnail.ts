export interface ThumbnailOptions {
    maxDimension?: number;
    type?: string;
    quality?: number;
}

interface DecodedImage {
    source: CanvasImageSource;
    width: number;
    height: number;
    close(): void;
}

/**
 * Downscales an image file into a thumbnail Blob. Uses createImageBitmap +
 * OffscreenCanvas when available, falling back to HTMLImageElement +
 * HTMLCanvasElement. Rejects for non-image files.
 */
export async function createThumbnail(file: Blob, options: ThumbnailOptions = {}): Promise<Blob> {
    const { maxDimension = 256, type = 'image/webp', quality = 0.8 } = options;

    if (!file.type.startsWith('image/')) {
        throw new Error('createThumbnail() requires an image file.');
    }

    const decoded = await decode(file);

    try {
        const scale = Math.min(1, maxDimension / Math.max(decoded.width, decoded.height));
        const width = Math.max(1, Math.round(decoded.width * scale));
        const height = Math.max(1, Math.round(decoded.height * scale));

        return await draw(decoded.source, width, height, type, quality);
    } finally {
        decoded.close();
    }
}

async function decode(file: Blob): Promise<DecodedImage> {
    if (typeof createImageBitmap === 'function') {
        const bitmap = await createImageBitmap(file);

        return {
            source: bitmap,
            width: bitmap.width,
            height: bitmap.height,
            close: () => bitmap.close(),
        };
    }

    const url = URL.createObjectURL(file);

    try {
        const image = new Image();
        await new Promise<void>((resolve, reject) => {
            image.onload = () => resolve();
            image.onerror = () => reject(new Error('The image could not be decoded.'));
            image.src = url;
        });

        return {
            source: image,
            width: image.naturalWidth,
            height: image.naturalHeight,
            close: () => {},
        };
    } finally {
        URL.revokeObjectURL(url);
    }
}

function draw(source: CanvasImageSource, width: number, height: number, type: string, quality: number): Promise<Blob> {
    if (typeof OffscreenCanvas === 'function') {
        const canvas = new OffscreenCanvas(width, height);
        const context = canvas.getContext('2d');

        if (!context) {
            return Promise.reject(new Error('Could not acquire a 2d canvas context.'));
        }

        context.drawImage(source, 0, 0, width, height);

        return canvas.convertToBlob({ type, quality });
    }

    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const context = canvas.getContext('2d');

    if (!context) {
        return Promise.reject(new Error('Could not acquire a 2d canvas context.'));
    }

    context.drawImage(source, 0, 0, width, height);

    return new Promise<Blob>((resolve, reject) => {
        canvas.toBlob(
            (blob) => (blob ? resolve(blob) : reject(new Error('The thumbnail could not be encoded.'))),
            type,
            quality,
        );
    });
}
