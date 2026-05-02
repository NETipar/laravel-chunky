import { useEffect, useRef, useState } from 'react';
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
export function useBatchCompletion(
    batchId: string | null,
    options: UseBatchCompletionOptions = {},
): UseBatchCompletionReturn {
    const [isWaiting, setIsWaiting] = useState(false);
    const [receivedVia, setReceivedVia] = useState<CompletionSource | null>(null);
    const [result, setResult] = useState<BatchCompletionResult | null>(null);

    // Track caller callbacks via ref so the watcher always invokes the
    // freshest closure. Without this each re-render that creates a new
    // `options = { onComplete: ... }` literal would cause the
    // subscription to fire stale closures.
    const optionsRef = useRef(options);
    optionsRef.current = options;

    const cleanupRef = useRef<(() => void) | null>(null);

    const stop = (): void => {
        cleanupRef.current?.();
        cleanupRef.current = null;
        setIsWaiting(false);
    };

    const cancelRef = useRef(stop);
    cancelRef.current = stop;

    useEffect(() => {
        if (!batchId) {
            stop();
            return;
        }

        const debounceMs = optionsRef.current.debounceMs ?? 50;

        const start = (): void => {
            setResult(null);
            setReceivedVia(null);
            setIsWaiting(true);

            const handleResult = (kind: 'complete' | 'partial', data: BatchCompletionResult): void => {
                setResult(data);
                setReceivedVia(data.source);
                setIsWaiting(false);
                cleanupRef.current = null;

                if (kind === 'partial') {
                    optionsRef.current.onPartiallyCompleted?.(data);
                } else {
                    optionsRef.current.onComplete?.(data);
                }
            };

            cleanupRef.current = watchBatchCompletion({
                batchId,
                statusEndpoint: optionsRef.current.statusEndpoint,
                echo: optionsRef.current.echo,
                channelPrefix: optionsRef.current.channelPrefix,
                pollStartDelayMs: optionsRef.current.pollStartDelayMs,
                pollIntervalMs: optionsRef.current.pollIntervalMs,
                pollMaxIntervalMs: optionsRef.current.pollMaxIntervalMs,
                pollBackoffFactor: optionsRef.current.pollBackoffFactor,
                timeoutMs: optionsRef.current.timeoutMs,
                headers: optionsRef.current.headers,
                withCredentials: optionsRef.current.withCredentials,
                onSubscribed: () => optionsRef.current.onSubscribed?.(),
                onSubscribeError: (err) => optionsRef.current.onSubscribeError?.(err),
                onComplete: (data) => handleResult('complete', data),
                onPartiallyCompleted: (data) => handleResult('partial', data),
                onTimeout: () => {
                    setIsWaiting(false);
                    cleanupRef.current = null;
                    optionsRef.current.onTimeout?.();
                },
                onError: (err, isFatal) => {
                    if (isFatal) {
                        setIsWaiting(false);
                        cleanupRef.current = null;
                    }

                    optionsRef.current.onError?.(err, isFatal);
                },
            });
        };

        if (debounceMs <= 0) {
            start();
            return () => stop();
        }

        const debounceTimer = setTimeout(start, debounceMs);

        return () => {
            clearTimeout(debounceTimer);
            stop();
        };
        // We intentionally only react to batchId changes — option
        // changes flow through optionsRef. Reading every option here
        // would cause endless re-subscription churn.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [batchId]);

    return {
        isWaiting,
        receivedVia,
        result,
        cancel: () => cancelRef.current(),
    };
}
