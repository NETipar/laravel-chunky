<?php

namespace NETipar\Chunky;

use Illuminate\Support\ServiceProvider;
use Livewire\Component;
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
        $this->registerContexts();
        $this->registerLivewireComponents();
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
