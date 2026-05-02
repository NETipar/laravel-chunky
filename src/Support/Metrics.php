<?php

declare(strict_types=1);

namespace NETipar\Chunky\Support;

/**
 * Observability hook surface. The package fires named metric events at
 * key lifecycle points (chunk uploaded, assembly started/completed, job
 * failed). Consumers register a handler in `config('chunky.metrics.<event>')`
 * and forward to Datadog, Prometheus, StatsD, or whatever else they use.
 *
 * Two handler shapes are supported:
 *
 *   1. **Class string** (recommended): `\App\Metrics\ChunkUploaded::class`.
 *      The class is resolved through the Laravel container, so its
 *      constructor can typehint dependencies (DatadogClient, Logger, …),
 *      and the package calls `__invoke(array $payload): void` on it.
 *      This shape is `config:cache`-compatible — closure handlers are
 *      not, because PHP cannot serialize closures.
 *
 *   2. **Callable** (legacy): a closure or `[$instance, 'method']` pair.
 *      Kept for backward compatibility with v0.13.0; tells `config:cache`
 *      to fall over, so prefer class strings in production.
 *
 * The handlers are best-effort: any exception they throw is swallowed
 * so an observability bug cannot break the upload pipeline.
 *
 * Example:
 *
 * ```php
 * // config/chunky.php
 * 'metrics' => [
 *     'chunk_uploaded' => \App\Metrics\ChunkUploaded::class,
 *     'assembly_completed' => \App\Metrics\AssemblyCompleted::class,
 * ],
 *
 * // app/Metrics/ChunkUploaded.php
 * class ChunkUploaded
 * {
 *     public function __construct(private DatadogClient $datadog) {}
 *
 *     public function __invoke(array $payload): void
 *     {
 *         $this->datadog->histogram('chunky.chunk_upload_ms', $payload['duration_ms']);
 *     }
 * }
 * ```
 */
class Metrics
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function emit(string $event, array $payload = []): void
    {
        $handler = config("chunky.metrics.{$event}");

        if ($handler === null || $handler === '') {
            return;
        }

        try {
            self::dispatch($handler, $payload);
        } catch (\Throwable) {
            // Swallow: an observability hook must never break the
            // upload pipeline. Configure proper monitoring on the
            // handler itself if you need visibility into its failures.
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function dispatch(mixed $handler, array $payload): void
    {
        // Class string: resolve via the container so the handler's
        // constructor dependencies are autowired.
        if (is_string($handler) && class_exists($handler)) {
            $instance = app($handler);

            // Prefer the explicit MetricsListener contract — when the
            // handler implements it the handle() entrypoint is the
            // documented one, regardless of whether __invoke also
            // exists.
            if ($instance instanceof MetricsListener) {
                $instance->handle($payload);

                return;
            }

            if (is_callable($instance)) {
                $instance($payload);

                return;
            }

            if (method_exists($instance, 'handle')) {
                $instance->handle($payload);

                return;
            }

            throw new \InvalidArgumentException(
                "Metrics handler {$handler} must implement ".MetricsListener::class
                .', be invokable (__invoke), or expose a handle() method.',
            );
        }

        // Callable: closure, [obj, 'method'], or 'Class@method'.
        // Closure handlers are kept for v0.13.0 backward compat but break
        // `php artisan config:cache` because closures aren't serialisable.
        // @deprecated since v0.20 — pass a class string instead so the
        // handler resolves through the container at runtime. Slated for
        // removal in v1.0.
        if (is_callable($handler)) {
            if ($handler instanceof \Closure) {
                @trigger_error(
                    "Closure handlers for chunky.metrics.{$event} are deprecated as of v0.20 and will be "
                    .'removed in v1.0 — use a class-string handler (resolved through the container) instead. '
                    .'Closure handlers also break `php artisan config:cache`.',
                    E_USER_DEPRECATED,
                );
            }

            $handler($payload);

            return;
        }

        throw new \InvalidArgumentException(
            'Metrics handler must be a class string or a callable; got '.get_debug_type($handler).'.',
        );
    }
}
