import { describe, it, expect, vi, afterEach } from 'vitest';
import { buildHeaders, getCsrfFromCookie } from './http';

function withCookie(value: string, fn: () => void): void {
    const spy = vi.spyOn(document, 'cookie', 'get').mockReturnValue(value);
    try {
        fn();
    } finally {
        spy.mockRestore();
    }
}

describe('http helpers', () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('extracts the XSRF token from the cookie when present', () => {
        withCookie('XSRF-TOKEN=abc123', () => {
            expect(getCsrfFromCookie()).toBe('abc123');
        });
    });

    it('URL-decodes the cookie value', () => {
        withCookie('XSRF-TOKEN=abc%2F123%3D', () => {
            expect(getCsrfFromCookie()).toBe('abc/123=');
        });
    });

    it('returns null when no XSRF cookie is set', () => {
        withCookie('', () => {
            expect(getCsrfFromCookie()).toBeNull();
        });
    });

    it('handles other cookies present alongside XSRF-TOKEN', () => {
        withCookie('foo=1; XSRF-TOKEN=abc; bar=2', () => {
            expect(getCsrfFromCookie()).toBe('abc');
        });
    });

    it('builds the standard JSON headers and merges extras', () => {
        withCookie('', () => {
            const headers = buildHeaders({ 'X-Custom': 'foo' });
            expect(headers).toMatchObject({
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-Custom': 'foo',
            });
        });
    });

    it('does not overwrite a user-supplied X-XSRF-TOKEN', () => {
        withCookie('XSRF-TOKEN=cookie-value', () => {
            const headers = buildHeaders({ 'X-XSRF-TOKEN': 'explicit-value' });
            expect(headers['X-XSRF-TOKEN']).toBe('explicit-value');
        });
    });

    it('falls back to the cookie token when no header is supplied', () => {
        withCookie('XSRF-TOKEN=fallback-value', () => {
            const headers = buildHeaders();
            expect(headers['X-XSRF-TOKEN']).toBe('fallback-value');
        });
    });
});
