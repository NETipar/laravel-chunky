import type { BatchCompletionResult, CompletionSource, EchoInstance } from '@netipar/chunky-core';
export interface UseBatchCompletionOptions {
    statusEndpoint?: string;
    echo?: EchoInstance;
    channelPrefix?: string;
    pollStartDelayMs?: number;
    pollIntervalMs?: number;
    pollMaxIntervalMs?: number;
    pollBackoffFactor?: number;
    timeoutMs?: number;
    /**
     * Wait this many milliseconds after the last `batchId` change before
     * starting the watcher. Protects against rapid null↔id flapping
     * which would otherwise teardown/setup an Echo subscription on
     * every tick. Default: 50ms.
     */
    debounceMs?: number;
    headers?: Record<string, string>;
    withCredentials?: boolean;
    onComplete?: (result: BatchCompletionResult) => void;
    onPartiallyCompleted?: (result: BatchCompletionResult) => void;
    onTimeout?: () => void;
    onError?: (error: Error, isFatal: boolean) => void;
    onSubscribed?: () => void;
    onSubscribeError?: (err: unknown) => void;
}
export interface UseBatchCompletionReturn {
    isWaiting: boolean;
    receivedVia: CompletionSource | null;
    result: BatchCompletionResult | null;
    cancel: () => void;
}
/**
 * React parity for `useBatchCompletion` from `@netipar/chunky-vue3`.
 * Subscribes to a batch's completion via Echo broadcast OR HTTP poll
 * (whichever wins) and exposes the result reactively. Caller-supplied
 * callbacks are tracked through a ref so a re-render that closes over
 * fresh state still sees that state.
 */
export declare function useBatchCompletion(batchId: string | null, options?: UseBatchCompletionOptions): UseBatchCompletionReturn;
