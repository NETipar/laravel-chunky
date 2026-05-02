export declare function getCsrfFromCookie(): string | null;
/**
 * Normalise the three shapes `HeadersInit` accepts (`Headers` instance,
 * `[string, string][]` tuple list, `Record<string, string>` object)
 * into a flat record. The package-internal headers map is kept as a
 * record because we read individual keys (`X-XSRF-TOKEN`,
 * `Idempotency-Key`); upgrading to `Headers` everywhere would force a
 * `.get()` rewrite without buying anything.
 */
export declare function normalizeHeaders(input?: HeadersInit): Record<string, string>;
export declare function buildHeaders(extra?: HeadersInit): Record<string, string>;
