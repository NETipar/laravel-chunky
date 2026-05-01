import { vi } from 'vitest';

/**
 * Build a mock fetch that returns successive responses from a queue.
 * Each entry can be a Response object, a partial JSON body, or a thrower.
 */
export function mockFetchSequence(
    responses: Array<Partial<{ status: number; json: unknown; throws: Error }>>,
): ReturnType<typeof vi.fn> {
    let i = 0;
    return vi.fn(async (_url: string, _init?: RequestInit) => {
        const r = responses[Math.min(i++, responses.length - 1)];

        if (r.throws) {
            throw r.throws;
        }

        return new Response(JSON.stringify(r.json ?? {}), {
            status: r.status ?? 200,
            headers: { 'Content-Type': 'application/json' },
        });
    });
}

export function makeFile(name: string, size: number = 1024): File {
    const content = new Uint8Array(size);
    return new File([content], name, { type: 'application/octet-stream' });
}
