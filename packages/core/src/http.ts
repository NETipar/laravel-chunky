export function getCsrfFromCookie(): string | null {
    if (typeof document === 'undefined') {
        return null;
    }

    const match = document.cookie
        .split('; ')
        .find((row) => row.startsWith('XSRF-TOKEN='));

    if (!match) {
        return null;
    }

    return decodeURIComponent(match.split('=')[1]);
}

/**
 * Normalise the three shapes `HeadersInit` accepts (`Headers` instance,
 * `[string, string][]` tuple list, `Record<string, string>` object)
 * into a flat record. The package-internal headers map is kept as a
 * record because we read individual keys (`X-XSRF-TOKEN`,
 * `Idempotency-Key`); upgrading to `Headers` everywhere would force a
 * `.get()` rewrite without buying anything.
 */
export function normalizeHeaders(input?: HeadersInit): Record<string, string> {
    if (!input) {
        return {};
    }

    if (typeof Headers !== 'undefined' && input instanceof Headers) {
        const out: Record<string, string> = {};
        input.forEach((value, key) => {
            out[key] = value;
        });
        return out;
    }

    if (Array.isArray(input)) {
        return Object.fromEntries(input);
    }

    // input here is Record<string, string>.
    return { ...(input as Record<string, string>) };
}

export function buildHeaders(extra?: HeadersInit): Record<string, string> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...normalizeHeaders(extra),
    };

    if (!headers['X-XSRF-TOKEN']) {
        const token = getCsrfFromCookie();

        if (token) {
            headers['X-XSRF-TOKEN'] = token;
        }
    }

    return headers;
}
