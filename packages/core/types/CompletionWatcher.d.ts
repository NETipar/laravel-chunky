import type { EchoInstance } from './echo';
export type CompletionSource = 'broadcast' | 'polling';
export type CompletionStatus = 'pending' | 'processing' | 'completed' | 'partially_completed' | 'expired';
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
export declare function watchBatchCompletion(options: CompletionWatcherOptions): () => void;
