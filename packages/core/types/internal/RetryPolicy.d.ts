import { UploadHttpError } from '../types';
/**
 * Default fatal HTTP statuses: client errors that won't change on
 * retry. Auth (401/403), not found (404, 410), payload-too-large
 * (413), unsupported media (415), validation (422).
 *
 * @internal
 */
export declare const DEFAULT_FATAL_STATUSES: ReadonlySet<number>;
export type RetryDecision = {
    retry: true;
    delayMs: number;
} | {
    retry: false;
};
export interface RetryContext {
    chunkIndex: number;
    retriesLeft: number;
    maxRetries: number;
}
export type AutoRetryOption = boolean | ((error: UploadHttpError | Error, context: {
    chunkIndex: number;
    retriesLeft: number;
}) => boolean);
/**
 * Encapsulates the retry decision + delay computation. Extracted from
 * ChunkUploader so the policy can be unit-tested in isolation and
 * potentially replaced by a host-app implementation in the future.
 *
 * @internal
 */
export declare class RetryPolicy {
    private readonly autoRetry;
    private readonly maxRetries;
    private readonly fatalStatuses;
    constructor(autoRetry: AutoRetryOption, maxRetries: number, fatalStatuses?: ReadonlySet<number>);
    decide(error: unknown, context: RetryContext): RetryDecision;
    /**
     * AWS-recommended "full jitter": uniformly-distributed wait in
     * [0, cap], where cap doubles every attempt. Different from the
     * naive "base + small jitter" that doesn't actually de-synchronise
     * many parallel workers.
     */
    private computeDelay;
}
