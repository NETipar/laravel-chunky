<?php

namespace NETipar\Chunky\Tests;

use NETipar\Chunky\ChunkyServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
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
        $app['config']->set('chunky.tracker', 'database');
        $app['config']->set('chunky.disk', 'local');
        $app['config']->set('chunky.chunk_size', 1024 * 1024);
        $app['config']->set('chunky.verify_integrity', true);
    }
}
