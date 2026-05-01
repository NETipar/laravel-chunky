import { vi } from 'vitest';
/**
 * Build a mock fetch that returns successive responses from a queue.
 * Each entry can be a Response object, a partial JSON body, or a thrower.
 */
export declare function mockFetchSequence(responses: Array<Partial<{
    status: number;
    json: unknown;
    throws: Error;
}>>): ReturnType<typeof vi.fn>;
export declare function makeFile(name: string, size?: number): File;
