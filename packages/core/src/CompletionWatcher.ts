import { listenForBatchComplete } from './echo';
import type {
    BatchCompletedData,
    BatchPartiallyCompletedData,
    EchoInstance,
} from './echo';
import { buildHeaders } from './http';

export type CompletionSource = 'broadcast' | 'polling';

export type CompletionStatus =
    | 'pending'
    | 'processing'
    | 'completed'
    | 'partially_completed'
    | 'expired';

export interface BatchStatusResponse {
    batch_id: string;
    total_files: number;
    completed_files: number;
    failed_files: number;
    pending_files: number;
    context: string | null;
    status: CompletionStatus;
    is_finished: boolean;
}

export interface BatchCompletionResult {
    source: CompletionSource;
    batchId: string;
    totalFiles: number;
    completedFiles: number;
    failedFiles: number;
    status: CompletionStatus;
}

export interface CompletionWatcherOptions {
    batchId: string;
    statusEndpoint?: string;
    echo?: EchoInstance;
    channelPrefix?: string;
    pollStartDelayMs?: number;
    pollIntervalMs?: number;
    pollMaxIntervalMs?: number;
    pollBackoffFactor?: number;
    timeoutMs?: number;
    /**
     * When set, every poll response that shows progress (the number of
     * processed files increased) extends the wall-clock timeout by this
     * many milliseconds. Lets a long-but-progressing batch keep running
     * past the static `timeoutMs` deadline without giving up.
     * Default 0 = disabled (static deadline).
     */
    extendTimeoutOnProgressMs?: number;
    headers?: Record<string, string>;
    withCredentials?: boolean;
    onComplete?: (result: BatchCompletionResult) => void;
    onPartiallyCompleted?: (result: BatchCompletionResult) => void;
    onTimeout?: () => void;
    onError?: (error: Error, isFatal: boolean) => void;
    onSubscribed?: () => void;
    onSubscribeError?: (err: unknown) => void;
}

const DEFAULT_STATUS_ENDPOINT = '/api/chunky/batch/{batchId}';
const DEFAULT_POLL_START_DELAY_MS = 1500;
const DEFAULT_POLL_INTERVAL_MS = 2000;
const DEFAULT_POLL_MAX_INTERVAL_MS = 30000;
const DEFAULT_POLL_BACKOFF_FACTOR = 1.5;
const DEFAULT_TIMEOUT_MS = 5 * 60 * 1000;

function isTerminalStatus(status: CompletionStatus): boolean {
    return status === 'completed' || status === 'partially_completed' || status === 'expired';
}

function toResult(
    source: CompletionSource,
    response: BatchStatusResponse,
): BatchCompletionResult {
    return {
        source,
        batchId: response.batch_id,
        totalFiles: response.total_files,
        completedFiles: response.completed_files,
        failedFiles: response.failed_files,
        status: response.status,
    };
}

export function watchBatchCompletion(options: CompletionWatcherOptions): () => void {
    const {
        batchId,
        statusEndpoint = DEFAULT_STATUS_ENDPOINT,
        echo,
        channelPrefix,
        pollStartDelayMs = DEFAULT_POLL_START_DELAY_MS,
        pollIntervalMs = DEFAULT_POLL_INTERVAL_MS,
        pollMaxIntervalMs = DEFAULT_POLL_MAX_INTERVAL_MS,
        pollBackoffFactor = DEFAULT_POLL_BACKOFF_FACTOR,
        timeoutMs = DEFAULT_TIMEOUT_MS,
        extendTimeoutOnProgressMs = 0,
        headers,
        withCredentials = true,
        onComplete,
        onPartiallyCompleted,
        onTimeout,
        onError,
        onSubscribed,
        onSubscribeError,
    } = options;

    const url = statusEndpoint.replace('{batchId}', batchId);

    let resolved = false;
    let echoCleanup: (() => void) | null = null;
    let echoSubscribed = false;
    let pollStartTimer: ReturnType<typeof setTimeout> | null = null;
    let pollTimer: ReturnType<typeof setTimeout> | null = null;
    let timeoutTimer: ReturnType<typeof setTimeout> | null = null;
    let abortController: AbortController | null = null;
    let currentPollIntervalMs = pollIntervalMs;
    let lastProcessedCount = -1;

    const cleanup = (): void => {
        echoCleanup?.();
        echoCleanup = null;

        if (pollStartTimer) {
            clearTimeout(pollStartTimer);
            pollStartTimer = null;
        }

        if (pollTimer) {
            clearTimeout(pollTimer);
            pollTimer = null;
        }

        if (timeoutTimer) {
            clearTimeout(timeoutTimer);
            timeoutTimer = null;
        }

        abortController?.abort();
        abortController = null;
    };

    const resolveBroadcast = (
        kind: 'complete' | 'partial',
        data: BatchCompletedData | BatchPartiallyCompletedData,
    ): void => {
        if (resolved) {
            return;
        }

        resolved = true;

        const completedFiles =
            kind === 'partial' ? (data as BatchPartiallyCompletedData).completedFiles : data.totalFiles;
        const failedFiles =
            kind === 'partial' ? (data as BatchPartiallyCompletedData).failedFiles : 0;

        const result: BatchCompletionResult = {
            source: 'broadcast',
            batchId: data.batchId,
            totalFiles: data.totalFiles,
            completedFiles,
            failedFiles,
            status: kind === 'partial' ? 'partially_completed' : 'completed',
        };

        cleanup();

        if (kind === 'partial') {
            onPartiallyCompleted?.(result);
        } else {
            onComplete?.(result);
        }
    };

    const resolvePolling = (response: BatchStatusResponse): void => {
        if (resolved) {
            return;
        }

        resolved = true;

        const result = toResult('polling', response);

        cleanup();

        if (response.status === 'partially_completed') {
            onPartiallyCompleted?.(result);
        } else {
            onComplete?.(result);
        }
    };

    const failFatal = (error: Error): void => {
        if (resolved) {
            return;
        }

        resolved = true;
        cleanup();
        onError?.(error, true);
    };

    const poll = async (): Promise<void> => {
        if (resolved) {
            return;
        }

        abortController = new AbortController();

        try {
            const response = await fetch(url, {
                method: 'GET',
                credentials: withCredentials ? 'include' : 'same-origin',
                headers: buildHeaders(headers),
                signal: abortController.signal,
            });

            if (!response.ok) {
                // Treat unrecoverable HTTP statuses as fatal so we don't
                // burn requests in a 2-second loop until the wall-clock
                // timeout. 401/403 mean the user is no longer authorised
                // (session expired or permissions changed); 404 means the
                // batch was forgotten / the wrong id.
                if (response.status === 401 || response.status === 403 || response.status === 404) {
                    failFatal(new Error(`Batch ${batchId} request failed: HTTP ${response.status}`));
                    return;
                }

                throw new Error(`Batch status request failed: HTTP ${response.status}`);
            }

            const body = (await response.json()) as BatchStatusResponse;

            if (isTerminalStatus(body.status)) {
                resolvePolling(body);
                return;
            }

            // Extend the wall-clock timeout when we observe progress, so a
            // long but still-progressing batch isn't killed by a static
            // deadline. Only kicks in when explicitly opted into via
            // extendTimeoutOnProgressMs.
            if (extendTimeoutOnProgressMs > 0) {
                const processed = body.completed_files + body.failed_files;

                if (processed > lastProcessedCount) {
                    lastProcessedCount = processed;

                    if (timeoutTimer) {
                        clearTimeout(timeoutTimer);
                        timeoutTimer = setTimeout(() => {
                            if (resolved) {
                                return;
                            }
                            resolved = true;
                            cleanup();
                            onTimeout?.();
                        }, extendTimeoutOnProgressMs);
                    }
                }
            }
        } catch (err) {
            if ((err as Error).name === 'AbortError') {
                return;
            }

            onError?.(err instanceof Error ? err : new Error('Polling failed'), false);
        }

        if (resolved) {
            return;
        }

        // Exponential backoff between polls so a long-running batch
        // doesn't hammer the status endpoint at the initial cadence
        // forever. Cap at pollMaxIntervalMs.
        pollTimer = setTimeout(poll, currentPollIntervalMs);

        currentPollIntervalMs = Math.min(
            pollMaxIntervalMs,
            Math.round(currentPollIntervalMs * pollBackoffFactor),
        );
    };

    const startPollNow = (): void => {
        if (pollStartTimer) {
            clearTimeout(pollStartTimer);
            pollStartTimer = null;
        }

        if (resolved) {
            return;
        }

        void poll();
    };

    if (echo) {
        echoCleanup = listenForBatchComplete(
            echo,
            batchId,
            {
                onComplete: (data) => resolveBroadcast('complete', data),
                onPartiallyCompleted: (data) => resolveBroadcast('partial', data),
                onSubscribed: () => {
                    echoSubscribed = true;

                    // Echo confirmed the subscription; the broadcast event
                    // will deliver the result, no need to also poll. Saves
                    // one HTTP request per active wait — a real cost when
                    // the dashboard has many watchers open.
                    if (pollStartTimer) {
                        clearTimeout(pollStartTimer);
                        pollStartTimer = null;
                    }

                    onSubscribed?.();
                },
                onSubscribeError: (err) => {
                    // Subscription failed — kick off polling immediately
                    // instead of waiting for the start delay, so the user
                    // gets a result roughly when they would have anyway.
                    startPollNow();
                    onSubscribeError?.(err);
                },
            },
            channelPrefix,
        );
    }

    pollStartTimer = setTimeout(() => {
        pollStartTimer = null;

        // If Echo got confirmed in the meantime, don't poll — the broadcast
        // event will resolve us. (The onSubscribed handler above also
        // clears this timer, but be defensive in case callbacks fire in an
        // unexpected order.)
        if (echoSubscribed) {
            return;
        }

        void poll();
    }, pollStartDelayMs);

    if (timeoutMs > 0) {
        timeoutTimer = setTimeout(() => {
            if (resolved) {
                return;
            }

            resolved = true;
            cleanup();
            onTimeout?.();
        }, timeoutMs);
    }

    return cleanup;
}
