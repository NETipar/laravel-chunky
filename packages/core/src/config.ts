import type { ChunkUploadOptions } from './types';

let globalDefaults: ChunkUploadOptions = {};

export function setDefaults(options: ChunkUploadOptions): void {
    globalDefaults = { ...options };
}

export function getDefaults(): ChunkUploadOptions {
    return globalDefaults;
}
