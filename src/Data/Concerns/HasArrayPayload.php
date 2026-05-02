<?php

declare(strict_types=1);

namespace NETipar\Chunky\Data\Concerns;

/**
 * Helpers for the snake_case ↔ camelCase mapping the package's DTOs use
 * when serialising to and from the tracker storage layer.
 *
 * Each DTO still owns its own `fromArray()` and `toArray()` methods —
 * the trait keeps the per-field coercion explicit (Carbon parsing,
 * enum casting, nullable string-int normalisation, etc.) and just
 * removes the snake_case key-juggling repetition.
 *
 * Adding a new field to a DTO is therefore: constructor parameter +
 * fromArray entry + toArray entry. The trait makes the latter two
 * lighter.
 */
trait HasArrayPayload
{
    /**
     * Read a snake_case key from the payload, or its camelCase fallback,
     * or a typed default.
     *
     * @param  array<string, mixed>  $data
     */
    protected static function read(array $data, string $snakeKey, mixed $default = null): mixed
    {
        if (array_key_exists($snakeKey, $data)) {
            return $data[$snakeKey];
        }

        $camel = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $snakeKey))));

        if (array_key_exists($camel, $data)) {
            return $data[$camel];
        }

        return $default;
    }

    /**
     * Cast a stored user_id to a string, returning null for missing /
     * empty values. The package treats user IDs as strings everywhere
     * so int / UUID / ULID shapes can coexist.
     */
    protected static function readUserId(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
