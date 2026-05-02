import { normalizeHeaders } from './http';
import type { ChunkUploadOptions } from './types';

export interface DefaultsScope {
    setDefaults(options: ChunkUploadOptions): void;
    mergeDefaults(options: ChunkUploadOptions): void;
    getDefaults(): ChunkUploadOptions;
    reset(): void;
}

/**
 * Deep-merge `incoming` into `base`. The `endpoints` and `headers`
 * objects are merged one level deep so a partial `setDefaults({ headers:
 * { Authorization: 'Bearer X' } })` followed by `setDefaults({ context:
 * 'photos' })` does NOT silently drop the Authorization header.
 *
 * Anything beyond a single nested level is replaced wholesale — the
 * config surface is intentionally flat-ish, and a recursive merge here
 * would let callers accidentally accumulate state from past sets.
 */
function mergeOptions(base: ChunkUploadOptions, incoming: ChunkUploadOptions): ChunkUploadOptions {
    return {
        ...base,
        ...incoming,
        headers: {
            ...normalizeHeaders(base.headers),
            ...normalizeHeaders(incoming.headers),
        },
        endpoints: {
            ...base.endpoints,
            ...incoming.endpoints,
        },
    };
}

let globalDefaults: ChunkUploadOptions = {};

/**
 * Replace the global defaults wholesale. Subsequent calls overwrite
 * previously-set keys (including a partial headers/endpoints object).
 * Use `mergeDefaults` if you want to accumulate state across calls.
 */
export function setDefaults(options: ChunkUploadOptions): void {
    globalDefaults = { ...options };
}

/**
 * Deep-merge new options into the existing defaults. Headers and
 * endpoints accumulate; everything else is replaced.
 */
export function mergeDefaults(options: ChunkUploadOptions): void {
    globalDefaults = mergeOptions(globalDefaults, options);
}

export function getDefaults(): ChunkUploadOptions {
    return globalDefaults;
}

/** Reset the global defaults — useful for test isolation. */
export function resetDefaults(): void {
    globalDefaults = {};
}

/**
 * Create an isolated defaults scope (e.g. for a multi-tenant app or for
 * a test that wants to set defaults without polluting the global
 * singleton).
 */
export function createDefaults(initial: ChunkUploadOptions = {}): DefaultsScope {
    let defaults: ChunkUploadOptions = { ...initial };

    return {
        setDefaults(options: ChunkUploadOptions): void {
            defaults = { ...options };
        },
        mergeDefaults(options: ChunkUploadOptions): void {
            defaults = mergeOptions(defaults, options);
        },
        getDefaults(): ChunkUploadOptions {
            return defaults;
        },
        reset(): void {
            defaults = {};
        },
    };
}
