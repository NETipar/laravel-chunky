/**
 * Low-level building blocks for advanced consumers who want to
 * orchestrate the upload flow themselves (custom retry policy, custom
 * worker pool, custom event-emitter integration). The high-level
 * `ChunkUploader` and `BatchUploader` classes are built on top of
 * these.
 *
 * Stability: marked `@internal` until v1.0; the API may change between
 * minor releases. Pin the package version exactly if you import from
 * `tools`.
 *
 * @internal
 */
export { EventEmitter } from './internal/EventEmitter';
export { RetryPolicy, DEFAULT_FATAL_STATUSES } from './internal/RetryPolicy';
export type { AutoRetryOption, RetryContext, RetryDecision } from './internal/RetryPolicy';
