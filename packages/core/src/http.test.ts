import { describe, expect, it, vi } from 'vitest';
import { jsonResponse } from './__tests__/support';
import { getJson, isRetryable, postJson } from './http';
import { ChunkyError } from './types';

describe('http', () => {
    it('returns the parsed JSON on success', async () => {
        const fetchImpl = vi.fn().mockResolvedValue(jsonResponse({ ok: true }));

        const data = await postJson('/x', { a: 1 }, { headers: {}, fetchImpl: fetchImpl as unknown as typeof fetch });

        expect(data).toEqual({ ok: true });
    });

    it('throws a ChunkyError built from the error envelope', async () => {
        const fetchImpl = vi.fn().mockResolvedValue(jsonResponse({ error: { code: 'upload_expired', message: 'gone' } }, 410));

        await expect(getJson('/x', { headers: {}, fetchImpl: fetchImpl as unknown as typeof fetch }))
            .rejects.toMatchObject({ code: 'upload_expired', status: 410 });
    });

    it('wraps a network failure as network_error', async () => {
        const fetchImpl = vi.fn().mockRejectedValue(new TypeError('offline'));

        await expect(postJson('/x', {}, { headers: {}, fetchImpl: fetchImpl as unknown as typeof fetch }))
            .rejects.toMatchObject({ code: 'network_error' });
    });

    it('classifies retryable errors', () => {
        expect(isRetryable(new ChunkyError('lock_timeout', '', 503))).toBe(true);
        expect(isRetryable(new ChunkyError('network_error', ''))).toBe(true);
        expect(isRetryable(new ChunkyError('invalid_state', '', 409))).toBe(false);
        expect(isRetryable(new Error('plain'))).toBe(false);
    });
});
