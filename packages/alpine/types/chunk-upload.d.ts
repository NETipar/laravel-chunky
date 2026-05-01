import { ChunkUploader } from '@netipar/chunky-core';
import type { UploadResult } from '@netipar/chunky-core';
/**
 * Minimal contract for the bits of Alpine.js the factory needs. Lets
 * the package compile without Alpine itself as a peer/dev dep, while
 * still keeping a type-safe entry point. Alpine 3's actual `Alpine`
 * object satisfies this interface (and many more methods we don't
 * use here).
 */
export interface AlpineLike {
    data<T>(name: string, factory: (...args: unknown[]) => T): void;
}
/**
 * Minimal type for the `this` context Alpine binds to component
 * methods. Alpine adds `$dispatch` (and `$watch`, `$el`, …) as
 * implicit fields; we only call `$dispatch`, so that's the only one
 * we type here.
 */
export interface AlpineContext {
    $dispatch(event: string, detail?: unknown): void;
}
export interface AlpineChunkUploadData {
    progress: number;
    isUploading: boolean;
    isPaused: boolean;
    isComplete: boolean;
    error: string | null;
    uploadId: string | null;
    uploadedChunks: number;
    totalChunks: number;
    currentFile: File | null;
    _uploader: ChunkUploader | null;
    init(): void;
    destroy(): void;
    upload(file: File, metadata?: Record<string, unknown>): Promise<UploadResult>;
    handleFileInput(event: Event): void;
    pause(): void;
    resume(): boolean;
    cancel(): void;
    retry(): boolean;
}
export declare function registerChunkUpload(Alpine: AlpineLike): void;
