<?php

declare(strict_types=1);

namespace NETipar\Chunky\Support;

/**
 * Optional contract for class-based metrics handlers. Implementing this
 * interface is NOT required — `Metrics::emit()` accepts any class with
 * `__invoke()` or `handle()` — but using it makes the contract explicit
 * and lets static analysers verify the payload shape.
 */
interface MetricsListener
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void;
}
