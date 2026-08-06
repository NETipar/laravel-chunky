import { type ChunkyConfig, type UploadOptions, type UploadState, type Uploader, UploadManager, configure } from '@netipar/chunky-core';

/**
 * The minimal slice of the Alpine global this package needs — typed so we don't
 * depend on alpinejs at build time (the consumer passes their own instance).
 */
export interface AlpineLike {
    data(name: string, factory: (...args: never[]) => object): void;
}

export interface ChunkyUploadComponent {
    state: UploadState | null;
    uploader: Uploader | null;
    /** Object URL preview for image files; null otherwise. */
    readonly previewUrl: string | null;
    start(file: File): void;
    onFileChange(event: Event): void;
}

function chunkyUpload(manager: UploadManager, options: UploadOptions): ChunkyUploadComponent {
    return {
        state: null,
        uploader: null,
        get previewUrl(): string | null {
            return this.uploader?.previewUrl() ?? null;
        },
        start(file: File): void {
            const uploader = manager.upload(file, options);
            this.uploader = uploader;
            uploader.subscribe((snapshot) => {
                this.state = snapshot;
            });
        },
        onFileChange(event: Event): void {
            const file = (event.target as HTMLInputElement).files?.[0];
            if (file) {
                this.start(file);
            }
        },
    };
}

/**
 * Registers `x-data="chunkyUpload({ profile: '...' })"`. Uploads are owned by a
 * module-level manager, so they survive Alpine component teardown.
 */
export function registerChunky(Alpine: AlpineLike, config: ChunkyConfig = {}): UploadManager {
    configure(config);
    const manager = new UploadManager(config);

    Alpine.data('chunkyUpload', (options: UploadOptions = {}) => chunkyUpload(manager, options));

    return manager;
}

export default registerChunky;
