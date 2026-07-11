<?php

declare(strict_types=1);

namespace NETipar\Chunky\Exceptions;

class InvalidConfigurationException extends ChunkyException
{
    public static function forKey(string $key, string $reason): self
    {
        return new self("Invalid chunky configuration for '{$key}': {$reason}");
    }
}
