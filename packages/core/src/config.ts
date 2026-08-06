import { RetryPolicy } from './internal/RetryPolicy';

export interface StorageLike {
    getItem(key: string): string | null;
    setItem(key: string, value: string): void;
    removeItem(key: string): void;
}

export interface ChunkyConfig {
    baseUrl?: string;
    headers?: Record<string, string>;
    concurrency?: number;
    retries?: number;
    fetch?: typeof fetch;
    resume?: boolean;
    storage?: StorageLike | null;
    pollIntervalMs?: number;
    maxPollIntervalMs?: number;
    watchTimeoutMs?: number;
    sleep?: (ms: number) => Promise<void>;
}

export interface ResolvedConfig {
    baseUrl: string;
    headers: Record<string, string>;
    concurrency: number;
    fetchImpl: typeof fetch;
    retryPolicy: RetryPolicy;
    resume: boolean;
    storage: StorageLike | null;
    pollIntervalMs: number;
    maxPollIntervalMs: number;
    watchTimeoutMs: number;
    sleep: (ms: number) => Promise<void>;
}

let globalConfig: ChunkyConfig = {};

export function configure(config: ChunkyConfig): void {
    globalConfig = { ...globalConfig, ...config };
}

function defaultStorage(): StorageLike | null {
    try {
        return globalThis.localStorage ?? null;
    } catch {
        return null;
    }
}

export function resolveConfig(overrides: ChunkyConfig = {}): ResolvedConfig {
    const merged = { ...globalConfig, ...overrides };
    const fetchImpl = merged.fetch ?? (typeof globalThis.fetch === 'function' ? globalThis.fetch.bind(globalThis) : undefined);

    if (!fetchImpl) {
        throw new Error('No fetch implementation available; pass config.fetch.');
    }

    const sleep = merged.sleep ?? ((ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms)));

    return {
        baseUrl: (merged.baseUrl ?? '/api/chunky').replace(/\/+$/, ''),
        headers: merged.headers ?? {},
        concurrency: merged.concurrency ?? 3,
        fetchImpl,
        // The same sleep drives retry backoff and watch polling, so tests can
        // make both instant with one injection.
        retryPolicy: new RetryPolicy({ retries: merged.retries ?? 3 }, undefined, sleep),
        resume: merged.resume ?? true,
        storage: merged.storage !== undefined ? merged.storage : defaultStorage(),
        pollIntervalMs: merged.pollIntervalMs ?? 1000,
        maxPollIntervalMs: merged.maxPollIntervalMs ?? 5000,
        watchTimeoutMs: merged.watchTimeoutMs ?? 300_000,
        sleep,
    };
}
