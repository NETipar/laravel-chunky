<?php

namespace NETipar\Chunky\Support;

/**
 * Observability hook surface. The package fires named metric events at
 * key lifecycle points (chunk uploaded, assembly started/completed, job
 * failed, batch completed). Consumers register a callback in
 * config('chunky.metrics.<event>') and forward to Datadog, Prometheus,
 * StatsD, or whatever else they use.
 *
 * The callbacks are best-effort: any exception thrown by a metric
 * callback is swallowed so observability bugs cannot break the upload
 * pipeline.
 */
class Metrics
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function emit(string $event, array $payload = []): void
    {
        $callback = config("chunky.metrics.{$event}");

        if (! is_callable($callback)) {
            return;
        }

        try {
            $callback($payload);
        } catch (\Throwable) {
            // Swallow: an observability hook must never break the
            // upload pipeline. Configure proper monitoring on the
            // callback itself if you need visibility into its failures.
        }
    }
}
