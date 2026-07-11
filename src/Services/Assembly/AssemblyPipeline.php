<?php

declare(strict_types=1);

namespace NETipar\Chunky\Services\Assembly;

use Throwable;

final class AssemblyPipeline
{
    /**
     * @param  list<AssemblyStep>  $steps
     */
    public function __construct(
        private readonly array $steps,
    ) {}

    /**
     * Run every step in order. If any step throws, the steps that already ran
     * are compensated in reverse order and the original error is re-thrown.
     */
    public function run(AssemblyState $state): void
    {
        $executed = [];

        try {
            foreach ($this->steps as $step) {
                $step->execute($state);
                $executed[] = $step;
            }
        } catch (Throwable $e) {
            foreach (array_reverse($executed) as $step) {
                $step->compensate($state);
            }

            throw $e;
        }
    }
}
