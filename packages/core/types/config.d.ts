import type { ChunkUploadOptions } from './types';
export interface DefaultsScope {
    setDefaults(options: ChunkUploadOptions): void;
    getDefaults(): ChunkUploadOptions;
}
export declare function setDefaults(options: ChunkUploadOptions): void;
export declare function getDefaults(): ChunkUploadOptions;
export declare function createDefaults(initial?: ChunkUploadOptions): DefaultsScope;
