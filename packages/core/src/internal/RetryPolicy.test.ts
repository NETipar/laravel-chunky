import { describe, expect, it, vi } from 'vitest';
import { RetryPolicy } from './RetryPolicy';

const instantSleep = (): Promise<void> => Promise.resolve();

describe('RetryPolicy', () => {
    it('retries retryable failures and eventually succeeds', async () => {
        const policy = new RetryPolicy({ retries: 3 }, () => 0.5, instantSleep);
        let calls = 0;

        const result = await policy.run(async () => {
            calls++;
            if (calls < 3) {
                throw new Error('transient');
            }

            return 'ok';
        }, () => true);

        expect(result).toBe('ok');
        expect(calls).toBe(3);
    });

    it('does not retry non-retryable failures', async () => {
        const policy = new RetryPolicy({ retries: 5 }, () => 0, instantSleep);
        let calls = 0;

        await expect(policy.run(async () => {
            calls++;
            throw new Error('fatal');
        }, () => false)).rejects.toThrow('fatal');

        expect(calls).toBe(1);
    });

    it('gives up after the configured number of retries', async () => {
        const policy = new RetryPolicy({ retries: 2 }, () => 0, instantSleep);
        let calls = 0;

        await expect(policy.run(async () => {
            calls++;
            throw new Error('always');
        }, () => true)).rejects.toThrow('always');

        expect(calls).toBe(3);
    });

    it('produces bounded jittered delays', () => {
        const policy = new RetryPolicy({ baseDelayMs: 100, maxDelayMs: 1000 }, () => 1);

        expect(policy.delayFor(0)).toBe(100);
        expect(policy.delayFor(1)).toBe(200);
        expect(policy.delayFor(10)).toBe(1000);
    });
});
