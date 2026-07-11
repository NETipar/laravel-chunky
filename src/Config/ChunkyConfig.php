<?php

declare(strict_types=1);

namespace NETipar\Chunky\Config;

use NETipar\Chunky\Exceptions\InvalidConfigurationException;

/**
 * Boot-time validated configuration. The raw config array is parsed into this
 * readonly object once; the rest of the package injects ChunkyConfig instead of
 * calling config('chunky.*'). Invalid values fail fast with the offending key.
 */
final readonly class ChunkyConfig
{
    /**
     * @param  list<string>  $routesMiddleware
     * @param  list<class-string>  $broadcastingExcept
     * @param  array<string, class-string>  $profiles
     */
    public function __construct(
        public string $tracker,
        public string $disk,
        public string $chunkDisk,
        public string $chunkDirectory,
        public int $chunkSize,
        public int $maxFileSize,
        public int $expirationHours,
        public int $metadataMaxKeys,
        public string $assemblyMode,
        public int $assemblySyncThreshold,
        public ?string $assemblyConnection,
        public ?string $assemblyQueue,
        public int $assemblyTimeout,
        public int $assemblyTries,
        public int $assemblyBackoff,
        public int $assemblyStaleClaimSeconds,
        public string $lockingDriver,
        public int $lockingTimeout,
        public int $idempotencyTtl,
        public string $integrityAlgorithm,
        public bool $integrityRequired,
        public bool $resumeFingerprint,
        public string $authorizer,
        public bool $routesEnabled,
        public string $routesPrefix,
        public array $routesMiddleware,
        public string $throttleInitiate,
        public string $throttleChunks,
        public bool $broadcastingEnabled,
        public array $broadcastingExcept,
        public ?string $broadcastingQueue,
        public array $profiles,
        public bool $cleanupEnabled,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        $chunks = self::section($config, 'chunks');
        $limits = self::section($config, 'limits');
        $assembly = self::section($config, 'assembly');
        $locking = self::section($config, 'locking');
        $idempotency = self::section($config, 'idempotency');
        $integrity = self::section($config, 'integrity');
        $resume = self::section($config, 'resume');
        $authorization = self::section($config, 'authorization');
        $routes = self::section($config, 'routes');
        $throttle = self::section($config, 'throttle');
        $broadcasting = self::section($config, 'broadcasting');
        $cleanup = self::section($config, 'cleanup');

        return new self(
            tracker: self::enum($config, 'tracker', ['database', 'filesystem']),
            disk: self::str($config, 'disk'),
            chunkDisk: self::str($chunks, 'chunks.disk'),
            chunkDirectory: self::str($chunks, 'chunks.directory'),
            chunkSize: self::positiveInt($chunks, 'chunks.size'),
            maxFileSize: self::nonNegativeInt($limits, 'limits.max_file_size'),
            expirationHours: self::positiveInt($limits, 'limits.expiration_hours'),
            metadataMaxKeys: self::positiveInt($limits, 'limits.metadata_max_keys'),
            assemblyMode: self::enum($assembly, 'assembly.mode', ['sync', 'queue', 'auto']),
            assemblySyncThreshold: self::nonNegativeInt($assembly, 'assembly.sync_threshold'),
            assemblyConnection: self::nullableStr($assembly, 'connection'),
            assemblyQueue: self::nullableStr($assembly, 'queue'),
            assemblyTimeout: self::positiveInt($assembly, 'assembly.timeout'),
            assemblyTries: self::nonNegativeInt($assembly, 'assembly.tries'),
            assemblyBackoff: self::nonNegativeInt($assembly, 'assembly.backoff'),
            assemblyStaleClaimSeconds: self::positiveInt($assembly, 'assembly.stale_claim_seconds'),
            lockingDriver: self::enum($locking, 'locking.driver', ['auto', 'cache', 'flock']),
            lockingTimeout: self::positiveInt($locking, 'locking.timeout'),
            idempotencyTtl: self::nonNegativeInt($idempotency, 'idempotency.ttl'),
            integrityAlgorithm: self::str($integrity, 'integrity.algorithm'),
            integrityRequired: self::bool($integrity, 'required'),
            resumeFingerprint: self::bool($resume, 'fingerprint'),
            authorizer: self::str($authorization, 'authorization.authorizer'),
            routesEnabled: self::bool($routes, 'enabled'),
            routesPrefix: self::str($routes, 'routes.prefix'),
            routesMiddleware: self::stringList($routes, 'routes.middleware'),
            throttleInitiate: self::str($throttle, 'throttle.initiate'),
            throttleChunks: self::str($throttle, 'throttle.chunks'),
            broadcastingEnabled: self::bool($broadcasting, 'enabled'),
            broadcastingExcept: self::classStringList($broadcasting, 'broadcasting.except'),
            broadcastingQueue: self::nullableStr($broadcasting, 'queue'),
            profiles: self::classStringMap($config, 'profiles'),
            cleanupEnabled: self::bool($cleanup, 'enabled'),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function section(array $config, string $key): array
    {
        $value = $config[$key] ?? [];

        if (! is_array($value)) {
            throw InvalidConfigurationException::forKey($key, 'must be an array.');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function str(array $data, string $key): string
    {
        $short = self::leaf($key);
        $value = $data[$short] ?? null;

        if (! is_string($value) || $value === '') {
            throw InvalidConfigurationException::forKey($key, 'must be a non-empty string.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function nullableStr(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw InvalidConfigurationException::forKey($key, 'must be a string or null.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $allowed
     */
    private static function enum(array $data, string $key, array $allowed): string
    {
        $short = self::leaf($key);
        $value = $data[$short] ?? null;

        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw InvalidConfigurationException::forKey($key, 'must be one of: '.implode(', ', $allowed).'.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function positiveInt(array $data, string $key): int
    {
        $value = self::intVal($data, $key);

        if ($value <= 0) {
            throw InvalidConfigurationException::forKey($key, 'must be a positive integer.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function nonNegativeInt(array $data, string $key): int
    {
        $value = self::intVal($data, $key);

        if ($value < 0) {
            throw InvalidConfigurationException::forKey($key, 'must be a non-negative integer.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function intVal(array $data, string $key): int
    {
        $short = self::leaf($key);
        $value = $data[$short] ?? null;

        if (is_int($value)) {
            return $value;
        }

        // env()-backed config may arrive as a numeric string (the v0.22.6 fix).
        if (is_string($value) && $value !== '' && ctype_digit(ltrim($value, '-'))) {
            return (int) $value;
        }

        throw InvalidConfigurationException::forKey($key, 'must be an integer.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function bool(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;

        if (! is_bool($value)) {
            throw InvalidConfigurationException::forKey($key, 'must be a boolean.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private static function stringList(array $data, string $key): array
    {
        $short = self::leaf($key);
        $value = $data[$short] ?? [];

        if (! is_array($value)) {
            throw InvalidConfigurationException::forKey($key, 'must be an array of strings.');
        }

        $result = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                throw InvalidConfigurationException::forKey($key, 'must contain only strings.');
            }
            $result[] = $item;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<class-string>
     */
    private static function classStringList(array $data, string $key): array
    {
        $short = self::leaf($key);
        $value = $data[$short] ?? [];

        if (! is_array($value)) {
            throw InvalidConfigurationException::forKey($key, 'must be an array of class-strings.');
        }

        $result = [];
        foreach ($value as $item) {
            if (! is_string($item) || $item === '') {
                throw InvalidConfigurationException::forKey($key, 'must contain only class-strings.');
            }
            /** @var class-string $item */
            $result[] = $item;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, class-string>
     */
    private static function classStringMap(array $config, string $key): array
    {
        $value = $config[$key] ?? [];

        if (! is_array($value)) {
            throw InvalidConfigurationException::forKey($key, 'must be a map of name => class-string.');
        }

        $result = [];
        foreach ($value as $name => $class) {
            if (! is_string($class) || $class === '') {
                throw InvalidConfigurationException::forKey("{$key}.{$name}", 'must be a class-string.');
            }
            /** @var class-string $class */
            $result[(string) $name] = $class;
        }

        return $result;
    }

    private static function leaf(string $key): string
    {
        $pos = strrpos($key, '.');

        return $pos === false ? $key : substr($key, $pos + 1);
    }
}
