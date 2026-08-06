import { ChunkyError } from './types';

export interface RequestConfig {
    headers: Record<string, string>;
    fetchImpl: typeof fetch;
}

async function parseBody(response: Response): Promise<unknown> {
    const text = await response.text();

    if (text === '') {
        return null;
    }

    try {
        return JSON.parse(text);
    } catch {
        return text;
    }
}

function toError(response: Response, body: unknown): ChunkyError {
    if (body !== null && typeof body === 'object' && 'error' in body) {
        const envelope = (body as { error?: { code?: unknown; message?: unknown } }).error;

        if (envelope && typeof envelope.code === 'string') {
            const message = typeof envelope.message === 'string' ? envelope.message : response.statusText;

            return new ChunkyError(envelope.code, message, response.status);
        }
    }

    return new ChunkyError('http_error', response.statusText || 'Request failed.', response.status);
}

async function request(
    method: string,
    url: string,
    config: RequestConfig,
    init: RequestInit,
): Promise<unknown> {
    let response: Response;

    try {
        response = await config.fetchImpl(url, {
            method,
            ...init,
            headers: { ...config.headers, ...(init.headers as Record<string, string> | undefined) },
        });
    } catch (error) {
        if (error instanceof DOMException && error.name === 'AbortError') {
            throw error;
        }

        throw new ChunkyError('network_error', error instanceof Error ? error.message : 'Network error.');
    }

    const body = await parseBody(response);

    if (!response.ok) {
        throw toError(response, body);
    }

    return body;
}

export function postJson(url: string, data: unknown, config: RequestConfig, signal?: AbortSignal): Promise<unknown> {
    return request('POST', url, config, {
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(data),
        signal,
    });
}

export function postForm(url: string, form: FormData, config: RequestConfig, signal?: AbortSignal): Promise<unknown> {
    return request('POST', url, config, {
        headers: { Accept: 'application/json' },
        body: form,
        signal,
    });
}

export function getJson(url: string, config: RequestConfig, signal?: AbortSignal): Promise<unknown> {
    return request('GET', url, config, {
        headers: { Accept: 'application/json' },
        signal,
    });
}

export function deleteJson(url: string, config: RequestConfig, signal?: AbortSignal): Promise<unknown> {
    return request('DELETE', url, config, {
        headers: { Accept: 'application/json' },
        signal,
    });
}

/**
 * Network errors, lock contention (503), and other 5xx are safe to retry;
 * 4xx (client errors) are not.
 */
export function isRetryable(error: unknown): boolean {
    if (!(error instanceof ChunkyError)) {
        return false;
    }

    if (error.code === 'network_error' || error.code === 'lock_timeout') {
        return true;
    }

    return error.status !== undefined && error.status >= 500;
}
