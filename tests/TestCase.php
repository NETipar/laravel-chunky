<?php

declare(strict_types=1);

namespace NETipar\Chunky\Tests;

use NETipar\Chunky\ChunkyServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            ChunkyServiceProvider::class,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('chunky.disk', 'local');
        $app['config']->set('chunky.chunks.disk', 'local');
        // Small chunks so tests can drive multi-chunk uploads with tiny payloads.
        $app['config']->set('chunky.chunks.size', 8);
    }
}
