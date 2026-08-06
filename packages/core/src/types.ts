export type UploadStatus =
    | 'idle'
    | 'uploading'
    | 'paused'
    | 'assembling'
    | 'completed'
    | 'failed'
    | 'cancelled';

export interface CompletedFile {
    path: string;
    size: number;
    url?: string;
}

export interface UploadResult {
    uploadId: string;
    status: 'completed' | 'failed' | 'cancelled';
    file: CompletedFile | null;
    payload: Record<string, unknown> | null;
}

export interface UploadState {
    status: UploadStatus;
    progress: number;
    uploadedChunks: number;
    totalChunks: number;
    bytesPerSecond: number | null;
    etaSeconds: number | null;
    file: { name: string; size: number };
    result: UploadResult | null;
    error: ChunkyError | null;
}

export type Unsubscribe = () => void;

export interface UploadEvents {
    stateChange: (state: UploadState) => void;
    progress: (progress: number) => void;
    completed: (result: UploadResult) => void;
    failed: (error: ChunkyError) => void;
    cancelled: () => void;
}

export interface UploadOptions {
    profile?: string;
    metadata?: Record<string, unknown>;
    fingerprint?: string | null;
    /** When set, the upload initiates against the batch member endpoint. */
    batchId?: string;
    /**
     * Compute the whole file's SHA-256 in parallel with the upload and send it
     * as `file_checksum` so the server verifies the assembled result
     * end-to-end. Off by default.
     */
    fileChecksum?: boolean;
}

export interface BatchOptions {
    profile?: string;
    metadata?: Record<string, unknown>;
    concurrency?: number;
}

/**
 * A machine-readable error. `code` matches the server ErrorCode enum, or one of
 * the client-side codes ('cancelled', 'network_error').
 */
export class ChunkyError extends Error {
    public readonly code: string;

    public readonly status?: number;

    constructor(code: string, message: string, status?: number) {
        super(message);
        this.name = 'ChunkyError';
        this.code = code;
        this.status = status;
    }
}
