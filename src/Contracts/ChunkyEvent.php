<?php

declare(strict_types=1);

namespace NETipar\Chunky\Contracts;

/**
 * Marker for all package events. Broadcasting is opt-in and gated per event by
 * BroadcastsChunkyEvent::broadcastWhen().
 */
interface ChunkyEvent {}
