<?php

declare(strict_types=1);

namespace NETipar\Chunky\Domain;

final readonly class Fingerprint
{
    private function __construct(
        public string $value,
    ) {}

    public static function fromString(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        return new self($trimmed);
    }

    public function equals(?self $other): bool
    {
        return $other !== null && hash_equals($this->value, $other->value);
    }
}
