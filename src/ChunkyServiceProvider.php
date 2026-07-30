<?php

declare(strict_types=1);

namespace NETipar\Chunky;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\NullStore;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider as CacheLockContract;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use NETipar\Chunky\Adapters\Database\DatabaseBatchRepository;
use NETipar\Chunky\Adapters\Database\DatabaseUploadRepository;
use NETipar\Chunky\Adapters\Filesystem\FilesystemBatchRepository;
use NETipar\Chunky\Adapters\Filesystem\FilesystemUploadRepository;
use NETipar\Chunky\Adapters\Filesystem\JsonFileStore;
use NETipar\Chunky\Adapters\Lock\CacheLockProvider;
use NETipar\Chunky\Adapters\Lock\FlockProvider;
use NETipar\Chunky\Adapters\S3\S3DirectTransport;
use NETipar\Chunky\Adapters\Storage\FlysystemChunkStore;
use NETipar\Chunky\Adapters\SystemClock;
use NETipar\Chunky\Authorization\Authorizer;
use NETipar\Chunky\Config\ChunkyConfig;
use NETipar\Chunky\Console\CleanupCommand;
use NETipar\Chunky\Console\DoctorCommand;
use NETipar\Chunky\Console\InstallCommand;
use NETipar\Chunky\Console\MakeProfileCommand;
use NETipar\Chunky\Exceptions\InvalidConfigurationException;
use NETipar\Chunky\Livewire\ChunkUpload;
use NETipar\Chunky\Ports\BatchRepository;
use NETipar\Chunky\Ports\ChunkStore;
use NETipar\Chunky\Ports\Clock;
use NETipar\Chunky\Ports\DirectUploadTransport;
use NETipar\Chunky\Ports\LockProvider;
use NETipar\Chunky\Ports\UploadRepository;
use NETipar\Chunky\Profiles\ProfileRegistry;
use RuntimeException;

class ChunkyServiceProvider extends ServiceProvider
{
    private bool $booted = false;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/chunky.php', 'chunky');

        // Bound (not singleton) so it always reflects the current config —
        // important for runtime/config-override scenarios and tests. Building it
        // is cheap; the services that consume it are resolved per request.
        $this->app->bind(ChunkyConfig::class, function (Application $app): ChunkyConfig {
            /** @var array<string, mixed> $config */
            $config = $app->make(Repository::class)->get('chunky', []);

            return ChunkyConfig::fromArray($config);
        });

        $this->app->singleton(Clock::class, SystemClock::class);

        $this->app->singleton(ProfileRegistry::class, function (Application $app): ProfileRegistry {
            $registry = new ProfileRegistry($app);
            $registry->loadFromConfig($app->make(ChunkyConfig::class)->profiles);

            return $registry;
        });

        $this->app->singleton(Authorizer::class, function (Application $app): Authorizer {
            $authorizer = $app->make($app->make(ChunkyConfig::class)->authorizer);

            if (! $authorizer instanceof Authorizer) {
                throw new RuntimeException('chunky.authorization.authorizer must implement '.Authorizer::class.'.');
            }

            return $authorizer;
        });

        $this->app->singleton(ChunkStore::class, function (Application $app): ChunkStore {
            $config = $app->make(ChunkyConfig::class);

            return new FlysystemChunkStore(
                $app->make(FilesystemFactory::class)->disk($config->chunkDisk),
                $config->chunkDirectory,
            );
        });

        $this->app->singleton(LockProvider::class, fn (Application $app): LockProvider => $this->makeLockProvider($app));

        $this->app->singleton(DirectUploadTransport::class, function (Application $app): DirectUploadTransport {
            $config = $app->make(ChunkyConfig::class);

            $disk = $config->directS3Disk
                ?? throw InvalidConfigurationException::forKey('transports.direct_s3.disk', 'must be set to use the direct_s3 transport.');

            /** @var array<string, mixed> $diskConfig */
            $diskConfig = $app->make(Repository::class)->get("filesystems.disks.{$disk}", []);

            return S3DirectTransport::fromDiskConfig($diskConfig, $config->directS3UrlTtl);
        });

        $this->app->singleton(UploadRepository::class, function (Application $app): UploadRepository {
            $config = $app->make(ChunkyConfig::class);

            if ($config->tracker === 'filesystem') {
                return new FilesystemUploadRepository(
                    new JsonFileStore($app->make(FilesystemFactory::class)->disk($config->chunkDisk)),
                    $app->make(LockProvider::class),
                );
            }

            return new DatabaseUploadRepository;
        });

        $this->app->singleton(BatchRepository::class, function (Application $app): BatchRepository {
            $config = $app->make(ChunkyConfig::class);

            if ($config->tracker === 'filesystem') {
                return new FilesystemBatchRepository(
                    new JsonFileStore($app->make(FilesystemFactory::class)->disk($config->chunkDisk)),
                    $app->make(LockProvider::class),
                );
            }

            return new DatabaseBatchRepository;
        });
    }

    public function boot(): void
    {
        $config = $this->app->make(ChunkyConfig::class);

        // Runs on every boot() so tests can re-verify after mutating config.
        $this->assertLockDriverCompatibility($config);

        if ($this->booted) {
            return;
        }

        $this->booted = true;

        $this->registerRoutes($config);
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'chunky');
        $this->registerLivewireComponents();

        require __DIR__.'/../routes/channels.php';

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/chunky.php' => $this->app->configPath('chunky.php'),
            ], 'chunky-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
            ], 'chunky-migrations');

            $this->commands([
                InstallCommand::class,
                DoctorCommand::class,
                CleanupCommand::class,
                MakeProfileCommand::class,
            ]);
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    private function registerLivewireComponents(): void
    {
        if (! class_exists(Livewire::class)) {
            return;
        }

        Livewire::component('chunky-upload', ChunkUpload::class);
    }

    private function registerRoutes(ChunkyConfig $config): void
    {
        if (! $config->routesEnabled) {
            return;
        }

        Route::group([
            'prefix' => $config->routesPrefix,
            'middleware' => $config->routesMiddleware,
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        });
    }

    private function makeLockProvider(Application $app): LockProvider
    {
        $config = $app->make(ChunkyConfig::class);
        $store = $app->make(CacheFactory::class)->store()->getStore();

        $useCache = match ($config->lockingDriver) {
            'cache' => true,
            'flock' => false,
            default => $store instanceof CacheLockContract,
        };

        if ($useCache && $store instanceof CacheLockContract) {
            return new CacheLockProvider($store, $config->lockingTimeout);
        }

        return new FlockProvider($app->storagePath('chunky-locks'), $config->lockingTimeout);
    }

    private function assertLockDriverCompatibility(ChunkyConfig $config): void
    {
        if ($config->lockingDriver !== 'cache') {
            return;
        }

        $store = $this->app->make(CacheFactory::class)->store()->getStore();

        $unsafe = $store instanceof ArrayStore
            || $store instanceof FileStore
            || $store instanceof NullStore;

        if ($unsafe || ! $store instanceof CacheLockContract) {
            throw new RuntimeException(
                'chunky.locking.driver=cache requires a cache driver that supports atomic locks (e.g. redis).',
            );
        }
    }
}
