<?php

declare(strict_types=1);

namespace NETipar\Chunky\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Shared infrastructure for the package's lifecycle events. Every event
 * is at least dispatchable; concrete subclasses opt into broadcasting
 * by having a non-empty `broadcastChannelSuffixes()` AND being toggled
 * on under `chunky.broadcasting.events.{key}` in config.
 *
 * The default per-event broadcast map is set in `config/chunky.php` —
 * the four "completion" events (UploadCompleted, UploadFailed,
 * BatchCompleted, BatchPartiallyCompleted) are on by default to
 * preserve back-compat. Per-chunk events are off by default because
 * broadcasting them is expensive.
 */
abstract class AbstractChunkyEvent implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    /**
     * The short identifier used in `chunky.broadcasting.events.<key>`
     * lookup. By convention the un-namespaced class basename.
     */
    abstract protected function broadcastEventKey(): string;

    /**
     * Channel suffixes appended to `chunky.broadcasting.channel_prefix`.
     * Examples: `["uploads.{$id}", "user.{$userId}"]`. Empty array
     * disables broadcasting for this event regardless of config.
     *
     * @return array<int, string>
     */
    abstract protected function broadcastChannelSuffixes(): array;

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $prefix = config('chunky.broadcasting.channel_prefix', 'chunky');

        return array_map(
            fn (string $suffix) => new PrivateChannel("{$prefix}.{$suffix}"),
            $this->broadcastChannelSuffixes(),
        );
    }

    public function broadcastAs(): string
    {
        return $this->broadcastEventKey();
    }

    public function broadcastQueue(): ?string
    {
        $queue = config('chunky.broadcasting.queue');

        return is_string($queue) ? $queue : null;
    }

    public function broadcastWhen(): bool
    {
        if (! config('chunky.broadcasting.enabled', false)) {
            return false;
        }

        // Per-event opt-in. The default map (config/chunky.php) sets
        // the completion events to true and the high-frequency ones
        // (per-chunk) to false. Operators can re-toggle without
        // changing the source of any event class.
        $key = $this->broadcastEventKey();

        return (bool) config("chunky.broadcasting.events.{$key}", false);
    }
}
