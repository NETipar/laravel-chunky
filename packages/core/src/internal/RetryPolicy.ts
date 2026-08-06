export interface RetryOptions {
    retries: number;
    baseDelayMs: number;
    maxDelayMs: number;
}

/**
 * Exponential backoff with full jitter. Deterministic when a custom `random`
 * and `sleep` are injected (used by tests).
 */
export class RetryPolicy {
    private readonly retries: number;

    private readonly baseDelayMs: number;

    private readonly maxDelayMs: number;

    constructor(
        options: Partial<RetryOptions> = {},
        private readonly random: () => number = Math.random,
        private readonly sleep: (ms: number) => Promise<void> = (ms) => new Promise((r) => setTimeout(r, ms)),
    ) {
        this.retries = options.retries ?? 3;
        this.baseDelayMs = options.baseDelayMs ?? 300;
        this.maxDelayMs = options.maxDelayMs ?? 10_000;
    }

    delayFor(attempt: number): number {
        const exponential = Math.min(this.maxDelayMs, this.baseDelayMs * 2 ** attempt);

        return Math.floor(exponential * this.random());
    }

    /**
     * Run `operation`, retrying while `isRetryable` returns true, up to the
     * configured number of retries.
     */
    async run<T>(operation: () => Promise<T>, isRetryable: (error: unknown) => boolean): Promise<T> {
        let attempt = 0;

        for (;;) {
            try {
                return await operation();
            } catch (error) {
                if (attempt >= this.retries || !isRetryable(error)) {
                    throw error;
                }

                await this.sleep(this.delayFor(attempt));
                attempt++;
            }
        }
    }
}
