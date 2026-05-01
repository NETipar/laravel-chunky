<?php

declare(strict_types=1);

namespace NETipar\Chunky;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Livewire\Component;
use NETipar\Chunky\Authorization\Authorizer;
use NETipar\Chunky\Authorization\DefaultAuthorizer;
use NETipar\Chunky\Console\CleanupCommand;
use NETipar\Chunky\Contracts\BatchTracker;
use NETipar\Chunky\Contracts\ChunkHandler;
use NETipar\Chunky\Contracts\UploadTracker;
use NETipar\Chunky\Enums\TrackerDriver;
use NETipar\Chunky\Handlers\DefaultChunkHandler;
use NETipar\Chunky\Support\ContextRegistry;
use NETipar\Chunky\Trackers\DatabaseBatchTracker;
use NETipar\Chunky\Trackers\DatabaseTracker;
use NETipar\Chunky\Trackers\FilesystemBatchTracker;
use NETipar\Chunky\Trackers\FilesystemTracker;

class ChunkyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/chunky.php', 'chunky');

        $this->app->singleton(ChunkHandler::class, DefaultChunkHandler::class);

        $this->app->singleton(UploadTracker::class, function () {
            return match (TrackerDriver::current()) {
                TrackerDriver::Filesystem => new FilesystemTracker,
                TrackerDriver::Database => new DatabaseTracker,
            };
        });

        $this->app->singleton(BatchTracker::class, function () {
            return match (TrackerDriver::current()) {
                TrackerDriver::Filesystem => new FilesystemBatchTracker,
                TrackerDriver::Database => new DatabaseBatchTracker,
            };
        });

        $this->app->singleton(ContextRegistry::class);
        $this->app->singleton(Authorizer::class, DefaultAuthorizer::class);

        $this->app->singleton(ChunkyManager::class, function ($app) {
            return new ChunkyManager(
                $app->make(ChunkHandler::class),
                $app->make(UploadTracker::class),
                $app->make(BatchTracker::class),
                $app->make(ContextRegistry::class),
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

        if (TrackerDriver::current() === TrackerDriver::Database) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'chunky');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/chunky'),
        ], 'chunky-views');

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'chunky');

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/chunky'),
        ], 'chunky-lang');

        $this->registerRateLimiter();
        $this->registerRoutes();
        $this->registerBroadcastChannels();
        $this->registerContexts();
        $this->registerLivewireComponents();
        $this->registerCleanup();
        $this->assertConfigurationIsValid();
        $this->assertLockDriverCompatibility();
    }

    /**
     * Register the "chunky" RateLimiter so the route group's
     * `throttle:chunky` middleware has something to consult. Keyed by
     * the authenticated user id when present, else by IP — keeps a
     * shared anonymous IP from being throttled across distinct users
     * behind the same NAT, while still capping anonymous abuse.
     *
     * Set `chunky.throttle.attempts = 0` to disable rate limiting
     * entirely (the limiter then returns no Limit instances).
     */
    private function registerRateLimiter(): void
    {
        if (! class_exists(RateLimiter::class)) {
            return;
        }

        RateLimiter::for('chunky', function (Request $request) {
            $attempts = (int) config('chunky.throttle.attempts', 120);
            $decay = (int) config('chunky.throttle.decay_minutes', 1);

            if ($attempts <= 0) {
                return Limit::none();
            }

            $key = optional($request->user())->getAuthIdentifier() ?: $request->ip();

            return Limit::perMinutes($decay, $attempts)->by((string) $key);
        });
    }

    /**
     * Catch typo'd config values at boot rather than letting them silently
     * fall through to a default branch. `chunky.tracker = 'datbase'` would
     * previously match the `default` arm of the singleton resolver and
     * create a FilesystemTracker — a confusing failure mode.
     */
    private function assertConfigurationIsValid(): void
    {
        $tracker = config('chunky.tracker', 'database');

        if (TrackerDriver::tryFrom((string) $tracker) === null) {
            $allowed = collect(TrackerDriver::cases())
                ->map(fn (TrackerDriver $d) => "'{$d->value}'")
                ->implode(', ');

            throw new \RuntimeException(
                "Invalid chunky.tracker value '{$tracker}'. Allowed: {$allowed}.",
            );
        }

        $lockDriver = config('chunky.locking.driver', 'flock');

        if (! in_array($lockDriver, ['flock', 'cache'], true)) {
            throw new \RuntimeException(
                "Invalid chunky.lock_driver value '{$lockDriver}'. Allowed: 'flock', 'cache'.",
            );
        }
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
        if (config('chunky.locking.driver', 'flock') !== 'cache') {
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

        if (! config('chunky.lifecycle.auto_cleanup', true)) {
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

        $registry = $this->app->make(ContextRegistry::class);

        foreach ($contexts as $contextClass) {
            $registry->registerClass($contextClass);
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
