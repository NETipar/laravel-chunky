<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\LockProvider as CacheLockContract;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use NETipar\Chunky\Adapters\Database\DatabaseBatchRepository;
use NETipar\Chunky\Adapters\Database\DatabaseUploadRepository;
use NETipar\Chunky\Adapters\Filesystem\FilesystemBatchRepository;
use NETipar\Chunky\Adapters\Filesystem\FilesystemUploadRepository;
use NETipar\Chunky\Adapters\Filesystem\JsonFileStore;
use NETipar\Chunky\Adapters\Lock\CacheLockProvider;
use NETipar\Chunky\Ports\BatchRepository;
use NETipar\Chunky\Ports\UploadRepository;

if (! function_exists('testLockProvider')) {
    function testLockProvider(): CacheLockProvider
    {
        /** @var CacheLockContract $store */
        $store = Cache::store('array')->getStore();

        return new CacheLockProvider($store);
    }
}

if (! function_exists('makeFilesystemUploadRepository')) {
    function makeFilesystemUploadRepository(): FilesystemUploadRepository
    {
        return new FilesystemUploadRepository(
            new JsonFileStore(Storage::disk('local')),
            testLockProvider(),
            'chunky/state/uploads',
        );
    }
}

if (! function_exists('makeFilesystemBatchRepository')) {
    function makeFilesystemBatchRepository(): FilesystemBatchRepository
    {
        return new FilesystemBatchRepository(
            new JsonFileStore(Storage::disk('local')),
            testLockProvider(),
            'chunky/state/batches',
        );
    }
}

if (! function_exists('makeUploadRepository')) {
    function makeUploadRepository(string $driver): UploadRepository
    {
        return $driver === 'filesystem'
            ? makeFilesystemUploadRepository()
            : new DatabaseUploadRepository;
    }
}

if (! function_exists('makeBatchRepository')) {
    function makeBatchRepository(string $driver): BatchRepository
    {
        return $driver === 'filesystem'
            ? makeFilesystemBatchRepository()
            : new DatabaseBatchRepository;
    }
}
