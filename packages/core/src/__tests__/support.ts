import { vi } from 'vitest';
import type { StorageLike } from '../config';

export function jsonResponse(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

export function makeFile(content: string, name = 'file.bin'): File {
    return new File([content], name, { type: 'application/octet-stream', lastModified: 1000 });
}

export function memoryStorage(): StorageLike {
    const map = new Map<string, string>();

    return {
        getItem: (key) => map.get(key) ?? null,
        setItem: (key, value) => {
            map.set(key, value);
        },
        removeItem: (key) => {
            map.delete(key);
        },
    };
}

export interface FakeServerOptions {
    chunkSize: number;
    mode?: 'sync' | 'queue';
    resumedChunks?: number[];
}

interface UploadRecord {
    total: number;
    size: number;
    received: Set<number>;
    completed: boolean;
}

/**
 * A minimal in-memory fake of the server that tracks chunk counts (robust to
 * upload order/concurrency) and supports single + batch endpoints.
 */
export function fakeServer(options: FakeServerOptions): { fetch: typeof fetch } {
    const uploads = new Map<string, UploadRecord>();
    let uploadCounter = 0;
    let batchCounter = 0;

    const completedBody = (size: number): unknown => ({
        status: 'completed',
        progress: 100,
        file: { path: 'final/f.bin', size },
        payload: { media_id: 7 },
    });

    const initiate = (body: { file_size?: number }): Response => {
        const id = `u${++uploadCounter}`;
        const size = body.file_size ?? 0;
        const total = Math.ceil(size / options.chunkSize);
        const resumed = options.resumedChunks ?? [];
        uploads.set(id, { total, size, received: new Set(resumed), completed: false });

        return jsonResponse({
            upload_id: id,
            batch_id: 'reused',
            chunk_size: options.chunkSize,
            total_chunks: total,
            resumed: resumed.length > 0,
            uploaded_chunks: resumed,
        }, 201);
    };

    const fetchImpl = vi.fn(async (input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
        const url = String(input);
        const method = init?.method ?? 'GET';
        const jsonBody = (): { file_size?: number; total_files?: number } =>
            typeof init?.body === 'string' ? JSON.parse(init.body) : {};

        if (url.endsWith('/batch') && method === 'POST') {
            return jsonResponse({ batch_id: `b${++batchCounter}`, total_files: jsonBody().total_files ?? 0 }, 201);
        }

        if (url.endsWith('/upload') && method === 'POST') {
            return initiate(jsonBody());
        }

        if (/\/batch\/[^/]+\/upload$/.test(url) && method === 'POST') {
            return initiate(jsonBody());
        }

        if (/\/batch\/[^/]+$/.test(url) && method === 'DELETE') {
            return jsonResponse({ status: 'cancelled' });
        }

        const chunk = url.match(/\/upload\/([^/]+)\/chunks$/);
        if (chunk && method === 'POST') {
            const record = uploads.get(chunk[1]);
            if (!record) {
                return jsonResponse({ error: { code: 'upload_not_found', message: 'x' } }, 404);
            }
            record.received.add(Number((init?.body as FormData).get('chunk_index')));

            if (record.received.size < record.total) {
                return jsonResponse({ status: 'uploading', uploaded_count: record.received.size, total_chunks: record.total, progress: 0 });
            }

            if (options.mode === 'queue') {
                return jsonResponse({ status: 'assembling', progress: 100 });
            }

            record.completed = true;

            return jsonResponse(completedBody(record.size));
        }

        const single = url.match(/\/upload\/([^/]+)$/);
        if (single && method === 'GET') {
            const record = uploads.get(single[1]);
            if (record && (record.completed || options.mode === 'queue')) {
                record.completed = true;

                return jsonResponse(completedBody(record.size));
            }

            return jsonResponse({ status: 'assembling', progress: 100 });
        }

        if (single && method === 'DELETE') {
            return jsonResponse({ status: 'cancelled' });
        }

        return jsonResponse({ error: { code: 'not_found', message: 'x' } }, 404);
    });

    return { fetch: fetchImpl as unknown as typeof fetch };
}
