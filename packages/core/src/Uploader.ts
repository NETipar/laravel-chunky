import type { ResolvedConfig } from './config';
import { CompletionWatcher } from './CompletionWatcher';
import { FingerprintStore, computeFingerprint } from './fingerprint';
import { EventEmitter } from './internal/EventEmitter';
import { sha256File } from './internal/sha256';
import { deleteJson, isRetryable, postForm, postJson } from './http';
import { ChunkyError, type UploadEvents, type UploadOptions, type UploadResult, type UploadState, type Unsubscribe } from './types';

interface InitiateResponse {
    upload_id: string;
    chunk_size: number;
    total_chunks: number;
    resumed: boolean;
    uploaded_chunks: number[];
}

interface ChunkResponse {
    status: string;
    file?: UploadResult['file'];
    payload?: UploadResult['payload'];
}

export class Uploader {
    private readonly emitter = new EventEmitter<UploadEvents>(['completed', 'failed', 'cancelled']);

    private readonly subscribers = new Set<(state: UploadState) => void>();

    private readonly fingerprintStore: FingerprintStore;

    private state: UploadState;

    private uploadId = '';

    private chunkSize = 0;

    private totalChunks = 0;

    private readonly uploaded = new Set<number>();

    private paused = false;

    private cancelled = false;

    private pausePromise: Promise<void> | null = null;

    private pauseResolver: (() => void) | null = null;

    private readonly controllers = new Set<AbortController>();

    private watcher: CompletionWatcher | null = null;

    private fingerprint: string | null = null;

    private startedAt = 0;

    private bytesUploaded = 0;

    private runPromise: Promise<UploadResult> | null = null;

    private fileHashPromise: Promise<string> | null = null;

    private fileChecksum: string | null = null;

    constructor(
        private readonly file: File,
        private readonly options: UploadOptions,
        private readonly config: ResolvedConfig,
    ) {
        this.fingerprintStore = new FingerprintStore(config.resume ? config.storage : null);
        this.state = {
            status: 'idle',
            progress: 0,
            uploadedChunks: 0,
            totalChunks: 0,
            bytesPerSecond: null,
            etaSeconds: null,
            file: { name: file.name, size: file.size },
            result: null,
            error: null,
        };
    }

    get id(): string {
        return this.uploadId;
    }

    getState(): UploadState {
        // `this.state` is replaced (never mutated) on every patch, so returning
        // it directly gives a stable reference between changes — required by
        // React's useSyncExternalStore.
        return this.state;
    }

    subscribe(listener: (state: UploadState) => void): Unsubscribe {
        this.subscribers.add(listener);
        listener(this.getState());

        return () => {
            this.subscribers.delete(listener);
        };
    }

    on<K extends keyof UploadEvents>(event: K, listener: UploadEvents[K]): Unsubscribe {
        return this.emitter.on(event, listener);
    }

    /**
     * Idempotent: the upload runs once. Calling upload() again (e.g. the
     * UploadManager started it and the caller also awaits it) returns the same
     * in-flight promise rather than restarting.
     */
    upload(): Promise<UploadResult> {
        this.runPromise ??= this.run();

        return this.runPromise;
    }

    private async run(): Promise<UploadResult> {
        if (this.state.status === 'completed' && this.state.result) {
            return this.state.result;
        }

        this.startedAt = Date.now();

        try {
            // Hash in parallel with the upload; the final chunk waits for it.
            if (this.options.fileChecksum) {
                this.fileHashPromise = sha256File(this.file);
            }

            await this.initiate();
            this.patch({ status: 'uploading', totalChunks: this.totalChunks, uploadedChunks: this.uploaded.size });
            const terminal = await this.uploadChunks();

            return await this.finalize(terminal);
        } catch (error) {
            if (this.cancelled) {
                throw new ChunkyError('cancelled', 'The upload was cancelled.');
            }

            const chunkyError = error instanceof ChunkyError
                ? error
                : new ChunkyError('failed', error instanceof Error ? error.message : 'Upload failed.');

            this.patch({ status: 'failed', error: chunkyError });
            this.emitter.emit('failed', chunkyError);

            throw chunkyError;
        }
    }

    pause(): void {
        if (this.state.status === 'uploading') {
            this.paused = true;
            this.patch({ status: 'paused' });
        }
    }

    resume(): boolean {
        if (!this.paused) {
            return false;
        }

        this.paused = false;
        this.patch({ status: 'uploading' });
        this.pauseResolver?.();
        this.pausePromise = null;
        this.pauseResolver = null;

        return true;
    }

    async cancel(): Promise<void> {
        this.cancelled = true;
        this.paused = false;
        this.pauseResolver?.();
        this.pausePromise = null;
        this.pauseResolver = null;
        this.abortInFlight();
        this.watcher?.stop();

        if (this.uploadId !== '') {
            try {
                await deleteJson(`${this.config.baseUrl}/upload/${this.uploadId}`, this.config);
            } catch {
                // Best effort; the server will expire the upload regardless.
            }
        }

        if (this.fingerprint) {
            this.fingerprintStore.remove(this.fingerprint);
        }

        this.patch({ status: 'cancelled' });
        this.emitter.emit('cancelled');
    }

    private async initiate(): Promise<void> {
        this.fingerprint = this.options.fingerprint ?? (this.config.resume ? await computeFingerprint(this.file) : null);

        const initiateUrl = this.options.batchId
            ? `${this.config.baseUrl}/batch/${this.options.batchId}/upload`
            : `${this.config.baseUrl}/upload`;

        const response = (await postJson(initiateUrl, {
            file_name: this.file.name,
            file_size: this.file.size,
            mime_type: this.file.type || null,
            profile: this.options.profile,
            metadata: this.options.metadata,
            fingerprint: this.fingerprint,
        }, this.config)) as InitiateResponse;

        this.uploadId = response.upload_id;
        this.chunkSize = response.chunk_size;
        this.totalChunks = response.total_chunks;
        for (const index of response.uploaded_chunks) {
            this.uploaded.add(index);
        }

        if (this.fingerprint) {
            this.fingerprintStore.set(this.fingerprint, this.uploadId);
        }
    }

    private async uploadChunks(): Promise<ChunkResponse | null> {
        const pending: number[] = [];
        for (let index = 0; index < this.totalChunks; index++) {
            if (!this.uploaded.has(index)) {
                pending.push(index);
            }
        }

        let cursor = 0;
        let terminal: ChunkResponse | null = null;

        const worker = async (): Promise<void> => {
            for (;;) {
                await this.gate();
                if (this.cancelled) {
                    return;
                }

                const position = cursor++;
                if (position >= pending.length) {
                    return;
                }

                // The last chunk carries the whole-file checksum, so its send
                // waits for the hash (local reads normally outpace the network).
                if (position === pending.length - 1 && this.fileHashPromise !== null) {
                    this.fileChecksum = await this.fileHashPromise;
                }

                const response = await this.uploadOneChunk(pending[position]);
                if (response.status === 'completed' || response.status === 'assembling') {
                    terminal = response;
                }
            }
        };

        const workerCount = Math.min(this.config.concurrency, Math.max(pending.length, 1));
        await Promise.all(Array.from({ length: workerCount }, () => worker()));

        if (this.cancelled) {
            throw new ChunkyError('cancelled', 'The upload was cancelled.');
        }

        return terminal;
    }

    private uploadOneChunk(index: number): Promise<ChunkResponse> {
        return this.config.retryPolicy.run(async () => {
            const start = index * this.chunkSize;
            const blob = this.file.slice(start, Math.min(start + this.chunkSize, this.file.size));

            const form = new FormData();
            form.append('chunk', blob, 'chunk');
            form.append('chunk_index', String(index));

            // The server keeps the first non-empty value and ignores repeats.
            if (this.fileChecksum !== null) {
                form.append('file_checksum', this.fileChecksum);
            }

            const controller = new AbortController();
            this.controllers.add(controller);

            try {
                const response = (await postForm(
                    `${this.config.baseUrl}/upload/${this.uploadId}/chunks`,
                    form,
                    this.config,
                    controller.signal,
                )) as ChunkResponse;

                this.uploaded.add(index);
                this.bytesUploaded += blob.size;
                this.reportProgress();

                return response;
            } finally {
                this.controllers.delete(controller);
            }
        }, isRetryable);
    }

    private async finalize(terminal: ChunkResponse | null): Promise<UploadResult> {
        if (terminal?.status === 'completed') {
            return this.succeed({
                uploadId: this.uploadId,
                status: 'completed',
                file: terminal.file ?? null,
                payload: terminal.payload ?? null,
            });
        }

        this.patch({ status: 'assembling', progress: 100 });
        this.watcher = new CompletionWatcher(this.uploadId, this.config);
        const result = await this.watcher.wait();

        if (result.status === 'completed') {
            return this.succeed(result);
        }

        const error = new ChunkyError('assembly_failed', 'The file could not be assembled.');
        this.patch({ status: 'failed', error });
        this.emitter.emit('failed', error);
        throw error;
    }

    private succeed(result: UploadResult): UploadResult {
        if (this.fingerprint) {
            this.fingerprintStore.remove(this.fingerprint);
        }

        this.patch({
            status: 'completed',
            progress: 100,
            uploadedChunks: this.totalChunks,
            result,
            bytesPerSecond: null,
            etaSeconds: null,
        });
        this.emitter.emit('completed', result);

        return result;
    }

    private gate(): Promise<void> {
        if (!this.paused) {
            return Promise.resolve();
        }

        this.pausePromise ??= new Promise<void>((resolve) => {
            this.pauseResolver = resolve;
        });

        return this.pausePromise;
    }

    private abortInFlight(): void {
        for (const controller of this.controllers) {
            controller.abort();
        }
        this.controllers.clear();
    }

    private reportProgress(): void {
        const uploadedChunks = this.uploaded.size;
        const progress = this.totalChunks > 0 ? Math.round((uploadedChunks / this.totalChunks) * 10_000) / 100 : 0;
        const elapsed = (Date.now() - this.startedAt) / 1000;
        const bytesPerSecond = elapsed > 0 ? Math.round(this.bytesUploaded / elapsed) : null;
        const remaining = this.file.size - this.bytesUploaded;
        const etaSeconds = bytesPerSecond && bytesPerSecond > 0 ? Math.round(remaining / bytesPerSecond) : null;

        this.patch({ uploadedChunks, progress, bytesPerSecond, etaSeconds });
        this.emitter.emit('progress', progress);
    }

    private patch(partial: Partial<UploadState>): void {
        this.state = { ...this.state, ...partial };
        const snapshot = this.getState();

        for (const listener of [...this.subscribers]) {
            listener(snapshot);
        }

        this.emitter.emit('stateChange', snapshot);
    }
}
