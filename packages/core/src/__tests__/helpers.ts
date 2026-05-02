import { vi } from 'vitest';

export interface MockResponse {
    status?: number;
    json?: unknown;
    body?: string;
    headers?: Record<string, string>;
    /** Throw this Error instead of resolving. */
    throws?: Error;
    /** Wait this many milliseconds before resolving. */
    delayMs?: number;
    /** Validate the request init before responding (useful for header / body assertions). */
    validateRequest?: (url: string, init: RequestInit | undefined) => void;
}

/**
 * Build a mock fetch that returns successive responses from a queue.
 * Each entry can configure status, JSON / raw body, headers, an
 * artificial delay, a thrown error, or a per-call request validator.
 *
 * Cast to `typeof fetch` so callers can assign the return value to
 * `globalThis.fetch` without per-test casts.
 */
export function mockFetchSequence(responses: Array<MockResponse>): typeof fetch {
    let i = 0;
    const impl = vi.fn(async (url: string, init?: RequestInit) => {
        const r = responses[Math.min(i++, responses.length - 1)];

        r.validateRequest?.(url, init);

        if (r.delayMs && r.delayMs > 0) {
            await new Promise((resolve) => setTimeout(resolve, r.delayMs));
        }

        if (r.throws) {
            throw r.throws;
        }

        const body = r.body ?? JSON.stringify(r.json ?? {});

        return new Response(body, {
            status: r.status ?? 200,
            headers: {
                'Content-Type': 'application/json',
                ...(r.headers ?? {}),
            },
        });
    });

    return impl as unknown as typeof fetch;
}

export function makeFile(name: string, size: number = 1024): File {
    const content = new Uint8Array(size);
    return new File([content], name, { type: 'application/octet-stream' });
}
