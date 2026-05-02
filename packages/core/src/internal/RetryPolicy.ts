import { UploadHttpError } from '../types';

/**
 * Default fatal HTTP statuses: client errors that won't change on
 * retry. Auth (401/403), not found (404, 410), payload-too-large
 * (413), unsupported media (415), validation (422).
 *
 * @internal
 */
export const DEFAULT_FATAL_STATUSES: ReadonlySet<number> = new Set([400, 401, 403, 404, 410, 413, 415, 422]);

export type RetryDecision =
    | { retry: true; delayMs: number }
    | { retry: false };

export interface RetryContext {
    chunkIndex: number;
    retriesLeft: number;
    maxRetries: number;
}

export type AutoRetryOption =
    | boolean
    | ((error: UploadHttpError | Error, context: { chunkIndex: number; retriesLeft: number }) => boolean);

/**
 * Encapsulates the retry decision + delay computation. Extracted from
 * ChunkUploader so the policy can be unit-tested in isolation and
 * potentially replaced by a host-app implementation in the future.
 *
 * @internal
 */
export class RetryPolicy {
    constructor(
        private readonly autoRetry: AutoRetryOption,
        private readonly maxRetries: number,
        private readonly fatalStatuses: ReadonlySet<number> = DEFAULT_FATAL_STATUSES,
    ) {}

    decide(error: unknown, context: RetryContext): RetryDecision {
        if (context.retriesLeft <= 0) {
            return { retry: false };
        }

        if (typeof this.autoRetry === 'function') {
            const err = error instanceof Error ? error : new Error(String(error));

            if (!this.autoRetry(err, { chunkIndex: context.chunkIndex, retriesLeft: context.retriesLeft })) {
                return { retry: false };
            }
        } else if (this.autoRetry === false) {
            return { retry: false };
        } else if (error instanceof UploadHttpError && this.fatalStatuses.has(error.status)) {
            return { retry: false };
        }

        return { retry: true, delayMs: this.computeDelay(context.retriesLeft) };
    }

    /**
     * AWS-recommended "full jitter": uniformly-distributed wait in
     * [0, cap], where cap doubles every attempt. Different from the
     * naive "base + small jitter" that doesn't actually de-synchronise
     * many parallel workers.
     */
    private computeDelay(retriesLeft: number): number {
        const cap = Math.pow(2, this.maxRetries - retriesLeft) * 1000;

        return Math.random() * cap;
    }
}
