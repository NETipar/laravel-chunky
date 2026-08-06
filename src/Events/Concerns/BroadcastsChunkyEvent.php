<?php

declare(strict_types=1);

namespace NETipar\Chunky\Events\Concerns;

use NETipar\Chunky\Config\ChunkyConfig;

trait BroadcastsChunkyEvent
{
    /**
     * Broadcast only when globally enabled and this event is not excluded.
     */
    public function broadcastWhen(): bool
    {
        $config = app(ChunkyConfig::class);

        return $config->broadcastingEnabled
            && ! in_array(static::class, $config->broadcastingExcept, true);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function versionedPayload(array $data): array
    {
        return ['v' => 1] + $data;
    }
}
