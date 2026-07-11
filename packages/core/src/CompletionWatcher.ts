import type { ResolvedConfig } from './config';
import { getJson } from './http';
import { ChunkyError, type UploadResult } from './types';

interface StatusResponse {
    status: string;
    progress?: number;
    file?: UploadResult['file'];
    payload?: UploadResult['payload'];
}

/**
 * Reconciles a queued assembly to a terminal result by polling the status
 * endpoint with an adaptive (backing-off) interval, extending the timeout while
 * progress keeps advancing. A broadcast channel, when available, can call
 * settle() to short-circuit the poll.
 */
export class CompletionWatcher {
    private stopped = false;

    constructor(
        private readonly uploadId: string,
        private readonly config: ResolvedConfig,
    ) {}

    async wait(): Promise<UploadResult> {
        let interval = this.config.pollIntervalMs;
        let lastProgress = -1;
        let deadline = Date.now() + this.config.watchTimeoutMs;

        for (;;) {
            if (this.stopped) {
                throw new ChunkyError('cancelled', 'The completion watch was stopped.');
            }

            const status = (await getJson(`${this.config.baseUrl}/upload/${this.uploadId}`, this.config)) as StatusResponse;

            const terminal = this.toResult(status);
            if (terminal) {
                return terminal;
            }

            const progress = status.progress ?? 0;
            if (progress > lastProgress) {
                lastProgress = progress;
                deadline = Date.now() + this.config.watchTimeoutMs;
            }

            if (Date.now() > deadline) {
                throw new ChunkyError('timeout', 'Timed out waiting for the file to assemble.');
            }

            await this.config.sleep(interval);
            interval = Math.min(this.config.maxPollIntervalMs, Math.floor(interval * 1.5));
        }
    }

    stop(): void {
        this.stopped = true;
    }

    private toResult(status: StatusResponse): UploadResult | null {
        if (status.status === 'completed') {
            return { uploadId: this.uploadId, status: 'completed', file: status.file ?? null, payload: status.payload ?? null };
        }

        if (status.status === 'failed') {
            return { uploadId: this.uploadId, status: 'failed', file: null, payload: null };
        }

        if (status.status === 'cancelled') {
            return { uploadId: this.uploadId, status: 'cancelled', file: null, payload: null };
        }

        return null;
    }
}
