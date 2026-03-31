import type { ChunkUploadOptions } from './types';

export interface DefaultsScope {
    setDefaults(options: ChunkUploadOptions): void;
    getDefaults(): ChunkUploadOptions;
}

let globalDefaults: ChunkUploadOptions = {};

export function setDefaults(options: ChunkUploadOptions): void {
    globalDefaults = { ...options };
}

export function getDefaults(): ChunkUploadOptions {
    return globalDefaults;
}

export function createDefaults(initial: ChunkUploadOptions = {}): DefaultsScope {
    let defaults: ChunkUploadOptions = { ...initial };

    return {
        setDefaults(options: ChunkUploadOptions): void {
            defaults = { ...options };
        },
        getDefaults(): ChunkUploadOptions {
            return defaults;
        },
    };
}
