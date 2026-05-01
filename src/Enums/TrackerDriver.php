<?php

declare(strict_types=1);

namespace NETipar\Chunky\Enums;

/**
 * Enum wrapper for `chunky.tracker` config values. Replaces 7+ inline
 * `config('chunky.tracker') === 'database'` magic-string checks with a
 * type-safe enum, and validates the config value at boot time.
 */
enum TrackerDriver: string
{
    case Database = 'database';
    case Filesystem = 'filesystem';

    public static function current(): self
    {
        $value = config('chunky.tracker', self::Database->value);

        return self::tryFrom((string) $value) ?? self::Database;
    }
}
