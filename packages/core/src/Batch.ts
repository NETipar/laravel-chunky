import type { ResolvedConfig } from './config';
import { EventEmitter } from './internal/EventEmitter';
import { deleteJson, postJson } from './http';
import { Uploader } from './Uploader';
import { ChunkyError, type BatchOptions, type UploadResult, type Unsubscribe } from './types';

export interface BatchState {
    status: 'idle' | 'uploading' | 'completed' | 'partially_completed' | 'cancelled';
    progress: number;
    total: number;
    completed: number;
    failed: number;
}

export interface BatchResult {
    batchId: string;
    status: 'completed' | 'partially_completed' | 'cancelled';
    results: UploadResult[];
}

interface BatchEvents {
    stateChange: (state: BatchState) => void;
    completed: (result: BatchResult) => void;
}

/**
 * A batch is a composition of Uploaders — it owns no chunk-level logic; it only
 * initiates the batch, binds member uploaders to it, and aggregates their state.
 */
export class Batch {
    private readonly emitter = new EventEmitter<BatchEvents>(['completed']);

    private readonly subscribers = new Set<(state: BatchState) => void>();

    private uploaders: Uploader[] = [];

    private batchId = '';

    constructor(
        private readonly files: File[],
        private readonly options: BatchOptions,
        private readonly config: ResolvedConfig,
    ) {}

    get id(): string {
        return this.batchId;
    }

    members(): readonly Uploader[] {
        return this.uploaders;
    }

    getState(): BatchState {
        const states = this.uploaders.map((u) => u.getState());
        const completed = states.filter((s) => s.status === 'completed').length;
        const failed = states.filter((s) => s.status === 'failed').length;
        const cancelled = states.some((s) => s.status === 'cancelled');
        const progress = states.length > 0
            ? Math.round((states.reduce((sum, s) => sum + s.progress, 0) / states.length) * 100) / 100
            : 0;

        let status: BatchState['status'] = 'uploading';
        if (this.batchId === '') {
            status = 'idle';
        } else if (cancelled) {
            status = 'cancelled';
        } else if (completed + failed === states.length && states.length > 0) {
            status = failed === 0 ? 'completed' : 'partially_completed';
        }

        return { status, progress, total: this.files.length, completed, failed };
    }

    subscribe(listener: (state: BatchState) => void): Unsubscribe {
        this.subscribers.add(listener);
        listener(this.getState());

        return () => {
            this.subscribers.delete(listener);
        };
    }

    on<K extends keyof BatchEvents>(event: K, listener: BatchEvents[K]): Unsubscribe {
        return this.emitter.on(event, listener);
    }

    async upload(): Promise<BatchResult> {
        const initiate = (await postJson(`${this.config.baseUrl}/batch`, {
            total_files: this.files.length,
            profile: this.options.profile,
            metadata: this.options.metadata,
        }, this.config)) as { batch_id: string };

        this.batchId = initiate.batch_id;

        this.uploaders = this.files.map((file) => {
            const uploader = new Uploader(file, {
                batchId: this.batchId,
                profile: this.options.profile,
                metadata: this.options.metadata,
            }, this.config);
            uploader.subscribe(() => this.notify());

            return uploader;
        });

        const results = await this.runPool(this.options.concurrency ?? 3);
        const state = this.getState();
        const result: BatchResult = {
            batchId: this.batchId,
            status: state.status === 'cancelled' ? 'cancelled' : (state.failed === 0 ? 'completed' : 'partially_completed'),
            results,
        };

        this.emitter.emit('completed', result);

        return result;
    }

    pause(): void {
        this.uploaders.forEach((u) => u.pause());
    }

    resume(): void {
        this.uploaders.forEach((u) => u.resume());
    }

    async cancel(): Promise<void> {
        await Promise.all(this.uploaders.map((u) => u.cancel()));

        if (this.batchId !== '') {
            try {
                await deleteJson(`${this.config.baseUrl}/batch/${this.batchId}`, this.config);
            } catch {
                // Best effort.
            }
        }

        this.notify();
    }

    private async runPool(concurrency: number): Promise<UploadResult[]> {
        const results: UploadResult[] = new Array<UploadResult>(this.uploaders.length);
        let cursor = 0;

        const worker = async (): Promise<void> => {
            for (;;) {
                const index = cursor++;
                if (index >= this.uploaders.length) {
                    return;
                }
                results[index] = await this.settle(this.uploaders[index]);
            }
        };

        await Promise.all(Array.from({ length: Math.min(concurrency, Math.max(this.uploaders.length, 1)) }, () => worker()));

        return results;
    }

    private async settle(uploader: Uploader): Promise<UploadResult> {
        try {
            return await uploader.upload();
        } catch (error) {
            const status = error instanceof ChunkyError && error.code === 'cancelled' ? 'cancelled' : 'failed';

            return { uploadId: uploader.id, status, file: null, payload: null };
        }
    }

    private notify(): void {
        const snapshot = this.getState();
        for (const listener of [...this.subscribers]) {
            listener(snapshot);
        }
        this.emitter.emit('stateChange', snapshot);
    }
}
