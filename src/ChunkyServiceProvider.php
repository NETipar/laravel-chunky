<?php

declare(strict_types=1);

namespace NETipar\Chunky;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use NETipar\Chunky\Config\ChunkyConfig;

class ChunkyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/chunky.php', 'chunky');

        $this->app->singleton(ChunkyConfig::class, function (Application $app): ChunkyConfig {
            /** @var array<string, mixed> $config */
            $config = $app->make(Repository::class)->get('chunky', []);

            return ChunkyConfig::fromArray($config);
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/chunky.php' => $this->app->configPath('chunky.php'),
            ], 'chunky-config');
        }
    }
}
