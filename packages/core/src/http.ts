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

export function buildHeaders(extra?: Record<string, string>): Record<string, string> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(extra ?? {}),
    };

    if (!headers['X-XSRF-TOKEN']) {
        const token = getCsrfFromCookie();

        if (token) {
            headers['X-XSRF-TOKEN'] = token;
        }
    }

    return headers;
}
