<?php

declare(strict_types=1);

namespace NETipar\Chunky\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Defence-in-depth on user-supplied metadata. The bare `array` rule with
 * `max:N` only caps the *number* of keys; without this rule a caller
 * could stuff a 100MB string into a single key, which would then
 * (a) bloat the tracker row, (b) bloat the broadcast payload, and
 * (c) potentially exhaust memory at decode time.
 *
 * Configurable via:
 * - chunky.metadata.max_keys             (handled by 'array|max:N' upstream)
 * - chunky.metadata.max_value_length     (per-string cap, bytes)
 * - chunky.metadata.max_total_size       (whole serialized payload, bytes)
 * - chunky.metadata.allowed_value_types  (gettype() values, default scalars)
 */
class ValidMetadata implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null) {
            return;
        }

        if (! is_array($value)) {
            $fail("The {$attribute} must be an associative array.");

            return;
        }

        $maxValueLen = (int) config('chunky.metadata.max_value_length', 1024);
        $maxTotalSize = (int) config('chunky.metadata.max_total_size', 16 * 1024);
        $allowedTypes = (array) config(
            'chunky.metadata.allowed_value_types',
            ['string', 'integer', 'boolean', 'double', 'NULL'],
        );

        $encoded = json_encode($value);

        if ($encoded === false) {
            $fail("The {$attribute} could not be encoded as JSON.");

            return;
        }

        if ($maxTotalSize > 0 && strlen($encoded) > $maxTotalSize) {
            $fail("The {$attribute} exceeds the maximum total size of {$maxTotalSize} bytes.");

            return;
        }

        foreach ($value as $k => $v) {
            $type = gettype($v);

            if (! in_array($type, $allowedTypes, true)) {
                $fail("The {$attribute}.{$k} has disallowed type '{$type}'.");

                return;
            }

            if ($maxValueLen > 0 && is_string($v) && strlen($v) > $maxValueLen) {
                $fail("The {$attribute}.{$k} exceeds {$maxValueLen} bytes.");

                return;
            }
        }
    }
}
