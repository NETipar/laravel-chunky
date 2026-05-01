import { type Ref } from 'vue';
import type { BatchCompletionResult, CompletionSource, EchoInstance } from '@netipar/chunky-core';
export interface UseBatchCompletionOptions {
    statusEndpoint?: string;
    echo?: EchoInstance;
    channelPrefix?: string;
    pollStartDelayMs?: number;
    pollIntervalMs?: number;
    timeoutMs?: number;
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
    isWaiting: Ref<boolean>;
    receivedVia: Ref<CompletionSource | null>;
    result: Ref<BatchCompletionResult | null>;
    cancel: () => void;
}
export declare function useBatchCompletion(batchId: Ref<string | null>, options?: UseBatchCompletionOptions): UseBatchCompletionReturn;
