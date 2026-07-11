<?php

declare(strict_types=1);

namespace NETipar\Chunky\Services\Assembly;

interface AssemblyStep
{
    public function execute(AssemblyState $state): void;

    /**
     * Undo this step's effect. Runs (in reverse order) for every step that
     * already executed when a later step throws.
     */
    public function compensate(AssemblyState $state): void;
}
