<?php

declare(strict_types=1);

namespace NETipar\Chunky\Profiles;

use Closure;
use Illuminate\Contracts\Container\Container;
use NETipar\Chunky\Exceptions\ChunkyException;
use NETipar\Chunky\Exceptions\ProfileNotFoundException;

final class ProfileRegistry
{
    /** @var array<string, UploadProfile|class-string|Closure> */
    private array $profiles = [];

    /** @var array<string, UploadProfile> */
    private array $resolved = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * @param  array<string, class-string>  $configProfiles
     */
    public function loadFromConfig(array $configProfiles): void
    {
        foreach ($configProfiles as $name => $class) {
            $this->profiles[$name] = $class;
        }
    }

    /**
     * @param  UploadProfile|class-string|Closure  $profile
     */
    public function register(string $name, UploadProfile|string|Closure $profile): void
    {
        $this->profiles[$name] = $profile;
        unset($this->resolved[$name]);
    }

    /**
     * @param  array{disk?: string, max_size?: int, mimes?: list<string>}  $options
     */
    public function simple(string $name, string $directory, array $options = []): void
    {
        $this->profiles[$name] = new SimpleProfile(
            $directory,
            $options['disk'] ?? null,
            $options['max_size'] ?? null,
            $options['mimes'] ?? [],
        );
        unset($this->resolved[$name]);
    }

    public function has(string $name): bool
    {
        return isset($this->profiles[$name]);
    }

    public function resolve(?string $name): ?UploadProfile
    {
        if ($name === null) {
            return null;
        }

        if (! isset($this->profiles[$name])) {
            throw ProfileNotFoundException::forName($name);
        }

        return $this->resolved[$name] ??= $this->instantiate($this->profiles[$name]);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->profiles);
    }

    /**
     * @param  UploadProfile|class-string|Closure  $profile
     */
    private function instantiate(UploadProfile|string|Closure $profile): UploadProfile
    {
        if ($profile instanceof UploadProfile) {
            return $profile;
        }

        $instance = $profile instanceof Closure ? $profile() : $this->container->make($profile);

        if (! $instance instanceof UploadProfile) {
            throw new ChunkyException('Registered profile must be an '.UploadProfile::class.'.');
        }

        return $instance;
    }
}
