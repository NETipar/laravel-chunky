import { ref, watch, getCurrentScope, onScopeDispose, type Ref } from 'vue';
import { watchBatchCompletion } from '@netipar/chunky-core';
import type {
    BatchCompletionResult,
    CompletionSource,
    EchoInstance,
} from '@netipar/chunky-core';

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
     * starting the watcher. Protects against rapid null↔id flapping (e.g.
     * route param churn) which would otherwise teardown/setup an Echo
     * subscription on every tick. Default: 50ms.
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
    isWaiting: Ref<boolean>;
    receivedVia: Ref<CompletionSource | null>;
    result: Ref<BatchCompletionResult | null>;
    cancel: () => void;
}

export function useBatchCompletion(
    batchId: Ref<string | null>,
    options: UseBatchCompletionOptions = {},
): UseBatchCompletionReturn {
    const isWaiting = ref(false);
    const receivedVia = ref<CompletionSource | null>(null);
    const result = ref<BatchCompletionResult | null>(null);

    let cleanup: (() => void) | null = null;
    let debounceTimer: ReturnType<typeof setTimeout> | null = null;

    const stop = (): void => {
        if (debounceTimer) {
            clearTimeout(debounceTimer);
            debounceTimer = null;
        }
        cleanup?.();
        cleanup = null;
        isWaiting.value = false;
    };

    const handleResult = (
        kind: 'complete' | 'partial',
        data: BatchCompletionResult,
    ): void => {
        result.value = data;
        receivedVia.value = data.source;
        isWaiting.value = false;
        cleanup = null;

        if (kind === 'partial') {
            options.onPartiallyCompleted?.(data);
        } else {
            options.onComplete?.(data);
        }
    };

    const debounceMs = options.debounceMs ?? 50;

    const startWatcher = (id: string): void => {
        result.value = null;
        receivedVia.value = null;
        isWaiting.value = true;

        cleanup = watchBatchCompletion({
            batchId: id,
            statusEndpoint: options.statusEndpoint,
            echo: options.echo,
            channelPrefix: options.channelPrefix,
            pollStartDelayMs: options.pollStartDelayMs,
            pollIntervalMs: options.pollIntervalMs,
            pollMaxIntervalMs: options.pollMaxIntervalMs,
            pollBackoffFactor: options.pollBackoffFactor,
            timeoutMs: options.timeoutMs,
            headers: options.headers,
            withCredentials: options.withCredentials,
            onSubscribed: options.onSubscribed,
            onSubscribeError: options.onSubscribeError,
            onComplete: (data) => handleResult('complete', data),
            onPartiallyCompleted: (data) => handleResult('partial', data),
            onTimeout: () => {
                isWaiting.value = false;
                cleanup = null;
                options.onTimeout?.();
            },
            onError: (err, isFatal) => {
                if (isFatal) {
                    isWaiting.value = false;
                    cleanup = null;
                }

                options.onError?.(err, isFatal);
            },
        });
    };

    watch(
        batchId,
        (id) => {
            stop();

            if (!id) {
                return;
            }

            if (debounceMs <= 0) {
                startWatcher(id);
                return;
            }

            debounceTimer = setTimeout(() => {
                debounceTimer = null;
                startWatcher(id);
            }, debounceMs);
        },
        { immediate: true },
    );

    if (getCurrentScope()) {
        onScopeDispose(stop);
    }

    return {
        isWaiting,
        receivedVia,
        result,
        cancel: stop,
    };
}
