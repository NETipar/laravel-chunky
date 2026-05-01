import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { watchBatchCompletion } from './CompletionWatcher';

describe('CompletionWatcher', () => {
    let originalFetch: typeof fetch;

    beforeEach(() => {
        originalFetch = globalThis.fetch;
    });

    afterEach(() => {
        globalThis.fetch = originalFetch;
        vi.useRealTimers();
    });

    it('treats 401 as fatal and stops polling', async () => {
        globalThis.fetch = vi.fn().mockResolvedValue(
            new Response(JSON.stringify({ message: 'unauthorized' }), { status: 401 }),
        ) as unknown as typeof globalThis.fetch;

        const onError = vi.fn();
        const cancel = watchBatchCompletion({
            batchId: 'b-1',
            pollStartDelayMs: 0,
            pollIntervalMs: 10,
            timeoutMs: 5000,
            onError,
        });

        await new Promise((r) => setTimeout(r, 30));
        cancel();

        expect(onError).toHaveBeenCalledTimes(1);
        expect(onError).toHaveBeenCalledWith(expect.any(Error), true);
    });

    it('treats 403 as fatal', async () => {
        globalThis.fetch = vi.fn().mockResolvedValue(
            new Response(null, { status: 403 }),
        ) as unknown as typeof globalThis.fetch;

        const onError = vi.fn();
        const cancel = watchBatchCompletion({
            batchId: 'b-2',
            pollStartDelayMs: 0,
            pollIntervalMs: 10,
            timeoutMs: 5000,
            onError,
        });

        await new Promise((r) => setTimeout(r, 30));
        cancel();

        expect(onError).toHaveBeenCalledWith(expect.any(Error), true);
    });

    it('keeps polling on transient 500 errors with isFatal=false', async () => {
        let calls = 0;
        globalThis.fetch = vi.fn().mockImplementation(async () => {
            calls++;
            // Always 500 — but onError must always be called with isFatal=false.
            return new Response(null, { status: 500 });
        }) as unknown as typeof globalThis.fetch;

        const onError = vi.fn();
        const cancel = watchBatchCompletion({
            batchId: 'b-3',
            pollStartDelayMs: 0,
            pollIntervalMs: 10,
            timeoutMs: 5000,
            onError,
        });

        await new Promise((r) => setTimeout(r, 60));
        cancel();

        expect(onError.mock.calls.length).toBeGreaterThanOrEqual(2);
        for (const call of onError.mock.calls) {
            expect(call[1]).toBe(false); // isFatal
        }
    });

    it('resolves with the body when polling sees a terminal status', async () => {
        const body = {
            batch_id: 'b-4',
            total_files: 3,
            completed_files: 3,
            failed_files: 0,
            pending_files: 0,
            context: null,
            status: 'completed',
            is_finished: true,
        };
        globalThis.fetch = vi.fn().mockResolvedValue(
            new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } }),
        ) as unknown as typeof globalThis.fetch;

        const onComplete = vi.fn();
        const cancel = watchBatchCompletion({
            batchId: 'b-4',
            pollStartDelayMs: 0,
            pollIntervalMs: 10,
            timeoutMs: 5000,
            onComplete,
        });

        await new Promise((r) => setTimeout(r, 30));
        cancel();

        expect(onComplete).toHaveBeenCalledWith(
            expect.objectContaining({ status: 'completed', source: 'polling' }),
        );
    });

    it('cancels itself silently when the returned cleanup is invoked', async () => {
        globalThis.fetch = vi.fn().mockResolvedValue(
            new Response(JSON.stringify({ status: 'pending' }), { status: 200 }),
        ) as unknown as typeof globalThis.fetch;

        const onComplete = vi.fn();
        const onError = vi.fn();
        const cancel = watchBatchCompletion({
            batchId: 'b-5',
            pollStartDelayMs: 0,
            pollIntervalMs: 10,
            timeoutMs: 5000,
            onComplete,
            onError,
        });

        cancel();
        await new Promise((r) => setTimeout(r, 30));

        // No callbacks should fire after cancel.
        expect(onComplete).not.toHaveBeenCalled();
    });

    it('extendTimeoutOnProgressMs starts the safeguard even when timeoutMs=0', async () => {
        // Regression test for the v0.17.2 fix: previously the progress
        // extension was a no-op when no static timeout was set, because
        // it only refreshed an existing timer. Now it (re)creates the
        // timer on every progress tick.
        let callCount = 0;
        globalThis.fetch = vi.fn().mockImplementation(() => {
            callCount++;
            // First two polls show progress (different processed counts),
            // subsequent polls don't — we expect the timer to fire after
            // extendTimeoutOnProgressMs of no progress.
            const completed = callCount === 1 ? 1 : 2;

            return Promise.resolve(
                new Response(
                    JSON.stringify({
                        batch_id: 'b',
                        total_files: 5,
                        completed_files: completed,
                        failed_files: 0,
                        pending_files: 5 - completed,
                        context: null,
                        status: 'processing',
                        is_finished: false,
                    }),
                    { status: 200, headers: { 'Content-Type': 'application/json' } },
                ),
            );
        }) as unknown as typeof globalThis.fetch;

        const onTimeout = vi.fn();
        const cancel = watchBatchCompletion({
            batchId: 'b',
            pollStartDelayMs: 0,
            pollIntervalMs: 5,
            pollMaxIntervalMs: 5,
            pollBackoffFactor: 1,
            timeoutMs: 0, // KEY: no static deadline.
            extendTimeoutOnProgressMs: 50,
            onTimeout,
        });

        // Wait long enough for at least 2 progress polls + the
        // extension window expiring with no further progress.
        await new Promise((r) => setTimeout(r, 200));
        cancel();

        expect(onTimeout).toHaveBeenCalled();
    });
});
