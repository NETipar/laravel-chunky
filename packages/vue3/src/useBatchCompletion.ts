import { onBeforeUnmount, ref, watch, getCurrentInstance, type Ref } from 'vue';
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

export function useBatchCompletion(
    batchId: Ref<string | null>,
    options: UseBatchCompletionOptions = {},
): UseBatchCompletionReturn {
    const isWaiting = ref(false);
    const receivedVia = ref<CompletionSource | null>(null);
    const result = ref<BatchCompletionResult | null>(null);

    let cleanup: (() => void) | null = null;

    const stop = (): void => {
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

    watch(
        batchId,
        (id) => {
            stop();

            if (!id) {
                return;
            }

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
        },
        { immediate: true },
    );

    if (getCurrentInstance()) {
        onBeforeUnmount(stop);
    }

    return {
        isWaiting,
        receivedVia,
        result,
        cancel: stop,
    };
}
