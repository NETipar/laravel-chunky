import type { ChunkUploadOptions } from './types';
export interface DefaultsScope {
    setDefaults(options: ChunkUploadOptions): void;
    mergeDefaults(options: ChunkUploadOptions): void;
    getDefaults(): ChunkUploadOptions;
    reset(): void;
}
/**
 * Replace the global defaults wholesale. Subsequent calls overwrite
 * previously-set keys (including a partial headers/endpoints object).
 * Use `mergeDefaults` if you want to accumulate state across calls.
 */
export declare function setDefaults(options: ChunkUploadOptions): void;
/**
 * Deep-merge new options into the existing defaults. Headers and
 * endpoints accumulate; everything else is replaced.
 */
export declare function mergeDefaults(options: ChunkUploadOptions): void;
export declare function getDefaults(): ChunkUploadOptions;
/** Reset the global defaults — useful for test isolation. */
export declare function resetDefaults(): void;
/**
 * Create an isolated defaults scope (e.g. for a multi-tenant app or for
 * a test that wants to set defaults without polluting the global
 * singleton).
 */
export declare function createDefaults(initial?: ChunkUploadOptions): DefaultsScope;
