import { Batch } from './Batch';
import { type ChunkyConfig, resolveConfig } from './config';
import { EventEmitter } from './internal/EventEmitter';
import { Uploader } from './Uploader';
import type { BatchOptions, UploadOptions, Unsubscribe } from './types';

interface ManagerEvents {
    change: (uploads: readonly Uploader[]) => void;
}

const ACTIVE = new Set(['uploading', 'assembling', 'paused']);
const TERMINAL = new Set(['completed', 'failed', 'cancelled']);

/**
 * A module-level owner of uploads. Because it holds strong references
 * independent of any component, uploads survive navigation — a framework
 * wrapper only binds to a manager entry; it never owns the upload.
 */
export class UploadManager {
    private readonly emitter = new EventEmitter<ManagerEvents>();

    private readonly registry: Uploader[] = [];

    // A cached snapshot with a stable reference between changes — required by
    // React's useSyncExternalStore, and equally correct for Vue.
    private snapshot: readonly Uploader[] = [];

    constructor(private readonly defaults: ChunkyConfig = {}) {}

    upload(file: File, options: UploadOptions = {}, config: ChunkyConfig = {}): Uploader {
        const uploader = new Uploader(file, options, resolveConfig({ ...this.defaults, ...config }));
        this.registry.push(uploader);
        uploader.subscribe(() => this.notify());
        void uploader.upload().catch(() => {
            // The failure is reflected in the uploader's state.
        });

        return uploader;
    }

    batch(files: File[], options: BatchOptions = {}, config: ChunkyConfig = {}): Batch {
        const batch = new Batch(files, options, resolveConfig({ ...this.defaults, ...config }));
        void batch.upload().catch(() => {
            // The failure is reflected in the batch state.
        });

        return batch;
    }

    uploads(): readonly Uploader[] {
        return this.snapshot;
    }

    subscribe(listener: (uploads: readonly Uploader[]) => void): Unsubscribe {
        return this.emitter.on('change', listener);
    }

    /**
     * Removes a terminal upload from the registry. Active uploads cannot be
     * removed (call cancel() first).
     */
    remove(uploadId: string): boolean {
        const index = this.registry.findIndex((u) => u.id === uploadId && TERMINAL.has(u.getState().status));

        if (index === -1) {
            return false;
        }

        const [removed] = this.registry.splice(index, 1);
        removed.revokePreview();
        this.notify();

        return true;
    }

    hasActive(): boolean {
        return this.registry.some((u) => ACTIVE.has(u.getState().status));
    }

    totalProgress(): number {
        if (this.registry.length === 0) {
            return 0;
        }

        const sum = this.registry.reduce((acc, u) => acc + u.getState().progress, 0);

        return Math.round((sum / this.registry.length) * 100) / 100;
    }

    /**
     * Warns on real page unload while uploads are active. SPA navigation does
     * not trigger this — the upload simply keeps running in the manager.
     */
    installUnloadGuard(target: EventTarget = globalThis as unknown as EventTarget): Unsubscribe {
        const handler = (event: Event): void => {
            if (this.hasActive()) {
                event.preventDefault();
                (event as unknown as { returnValue: string }).returnValue = '';
            }
        };

        target.addEventListener('beforeunload', handler);

        return () => target.removeEventListener('beforeunload', handler);
    }

    private notify(): void {
        this.snapshot = [...this.registry];
        this.emitter.emit('change', this.snapshot);
    }
}

export const manager = new UploadManager();
