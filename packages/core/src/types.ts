export interface ChunkUploadOptions {
    chunkSize?: number;
    maxConcurrent?: number;
    autoRetry?: boolean;
    maxRetries?: number;
    headers?: Record<string, string>;
    withCredentials?: boolean;
    context?: string;
    endpoints?: {
        initiate?: string;
        upload?: string;
        status?: string;
    };
}

export interface UploadResult {
    uploadId: string;
    fileName: string;
    fileSize: number;
    totalChunks: number;
}

export interface UploadError {
    uploadId: string | null;
    chunkIndex?: number;
    message: string;
    cause?: unknown;
}

export interface ChunkInfo {
    index: number;
    size: number;
    checksum: string | null;
    uploadId: string;
}

export interface ProgressEvent {
    uploadId: string;
    loaded: number;
    total: number;
    percentage: number;
    chunkIndex: number;
    totalChunks: number;
}

export interface InitiateResponse {
    upload_id: string;
    chunk_size: number;
    total_chunks: number;
}

export interface ChunkUploadResponse {
    chunk_index: number;
    is_complete: boolean;
    uploaded_count: number;
    total_chunks: number;
    progress: number;
}

export interface StatusResponse {
    upload_id: string;
    file_name: string;
    file_size: number;
    mime_type: string | null;
    chunk_size: number;
    total_chunks: number;
    uploaded_chunks: number[];
    uploaded_count: number;
    progress: number;
    status: string;
    final_path: string | null;
}

export interface ChunkUploaderState {
    progress: number;
    isUploading: boolean;
    isPaused: boolean;
    isComplete: boolean;
    error: string | null;
    uploadId: string | null;
    uploadedChunks: number;
    totalChunks: number;
    currentFile: File | null;
}

export type ChunkUploaderEventMap = {
    progress: ProgressEvent;
    chunkUploaded: ChunkInfo;
    complete: UploadResult;
    error: UploadError;
    stateChange: ChunkUploaderState;
};

export type Unsubscribe = () => void;
