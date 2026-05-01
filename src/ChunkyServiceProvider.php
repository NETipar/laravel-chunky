<?php

declare(strict_types=1);

namespace NETipar\Chunky;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;
use Livewire\Component;
use NETipar\Chunky\Authorization\Authorizer;
use NETipar\Chunky\Authorization\DefaultAuthorizer;
use NETipar\Chunky\Console\CleanupCommand;
use NETipar\Chunky\Contracts\ChunkHandler;
use NETipar\Chunky\Contracts\UploadTracker;
use NETipar\Chunky\Handlers\DefaultChunkHandler;
use NETipar\Chunky\Trackers\DatabaseTracker;
use NETipar\Chunky\Trackers\FilesystemTracker;

class ChunkyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/chunky.php', 'chunky');

        $this->app->singleton(ChunkHandler::class, DefaultChunkHandler::class);

        $this->app->singleton(UploadTracker::class, function () {
            return match (config('chunky.tracker')) {
                'filesystem' => new FilesystemTracker,
                default => new DatabaseTracker,
            };
        });

        $this->app->singleton(Authorizer::class, DefaultAuthorizer::class);

        $this->app->singleton(ChunkyManager::class, function ($app) {
            return new ChunkyManager(
                $app->make(ChunkHandler::class),
                $app->make(UploadTracker::class),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/chunky.php' => config_path('chunky.php'),
        ], 'chunky-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'chunky-migrations');

        if (config('chunky.tracker') === 'database') {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'chunky');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/chunky'),
        ], 'chunky-views');

        $this->registerRoutes();
        $this->registerBroadcastChannels();
        $this->registerContexts();
        $this->registerLivewireComponents();
        $this->registerCleanup();
        $this->assertLockDriverCompatibility();
    }

    /**
     * If the operator opted into Cache::lock-backed locking, the configured
     * cache driver must actually support atomic locks. The `array` and
     * `file` drivers do not — they'll silently no-op (`array` per request)
     * or use last-write-wins file flocks that don't work across hosts. Fail
     * fast on misconfig instead of letting a race condition surface in
     * production.
     */
    private function assertLockDriverCompatibility(): void
    {
        if (config('chunky.lock_driver', 'flock') !== 'cache') {
            return;
        }

        $store = config('cache.default');
        $unsafe = ['array', 'file'];

        if (in_array($store, $unsafe, true)) {
            throw new \RuntimeException(
                "chunky.lock_driver = 'cache' requires a cache driver that supports atomic locks "
                ."(redis, memcached, database, dynamodb). The current cache.default is '{$store}', "
                .'which does not. Switch the cache driver or set chunky.lock_driver back to "flock".',
            );
        }
    }

    private function registerBroadcastChannels(): void
    {
        if (! config('chunky.broadcasting.enabled', false)) {
            return;
        }

        if (! config('chunky.broadcasting.register_channels', true)) {
            return;
        }

        if (! class_exists(Broadcast::class)) {
            return;
        }

        require __DIR__.'/../routes/channels.php';
    }

    private function registerCleanup(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([CleanupCommand::class]);

        if (! config('chunky.auto_cleanup', true)) {
            return;
        }

        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);
            $schedule->command('chunky:cleanup')->daily()->name('chunky:cleanup')->withoutOverlapping();
        });
    }

    private function registerContexts(): void
    {
        $contexts = config('chunky.contexts', []);

        if (empty($contexts)) {
            return;
        }

        $manager = $this->app->make(ChunkyManager::class);

        foreach ($contexts as $contextClass) {
            $manager->register($contextClass);
        }
    }

    private function registerRoutes(): void
    {
        $routeConfig = config('chunky.routes', []);

        $this->app['router']
            ->prefix($routeConfig['prefix'] ?? 'api/chunky')
            ->middleware($routeConfig['middleware'] ?? ['api'])
            ->group(__DIR__.'/../routes/api.php');
    }

    private function registerLivewireComponents(): void
    {
        if (! class_exists(Component::class)) {
            return;
        }

        \Livewire\Livewire::component('chunky-upload', Livewire\ChunkUpload::class);
    }
}
