<?php

use Illuminate\Support\Facades\Event;
use NETipar\Chunky\ChunkyContext;
use NETipar\Chunky\ChunkyManager;
use NETipar\Chunky\Contracts\ChunkHandler;
use NETipar\Chunky\Contracts\UploadTracker;
use NETipar\Chunky\Data\InitiateResult;
use NETipar\Chunky\Data\UploadMetadata;
use NETipar\Chunky\Events\UploadInitiated;

it('registers and retrieves contexts', function () {
    $manager = app(ChunkyManager::class);

    expect($manager->hasContext('test'))->toBeFalse();

    $manager->context('test', rules: fn () => [
        'file_size' => ['max:1000'],
    ]);

    expect($manager->hasContext('test'))->toBeTrue();
    expect($manager->getContextRules('test'))->toBe(['file_size' => ['max:1000']]);
});

it('returns empty rules for unregistered context', function () {
    $manager = app(ChunkyManager::class);

    expect($manager->getContextRules('nonexistent'))->toBe([]);
});

it('registers and retrieves save callbacks', function () {
    $manager = app(ChunkyManager::class);
    $called = false;

    $manager->context('save-test', save: function (UploadMetadata $metadata) use (&$called) {
        $called = true;
    });

    $callback = $manager->getContextSaveCallback('save-test');

    expect($callback)->toBeInstanceOf(Closure::class);

    $metadata = new UploadMetadata(
        uploadId: 'test',
        fileName: 'test.txt',
        fileSize: 1000,
        mimeType: null,
        chunkSize: 500,
        totalChunks: 2,
        disk: 'local',
        context: 'save-test',
    );

    $callback($metadata);

    expect($called)->toBeTrue();
});

it('returns null save callback for context without save', function () {
    $manager = app(ChunkyManager::class);

    $manager->context('rules-only', rules: fn () => ['file_size' => ['max:1000']]);

    expect($manager->getContextSaveCallback('rules-only'))->toBeNull();
});

it('registers a class-based context', function () {
    $contextClass = new class extends ChunkyContext
    {
        public function name(): string
        {
            return 'class_based';
        }

        public function rules(): array
        {
            return ['file_size' => ['max:2048']];
        }

        public function save(UploadMetadata $metadata): void {}
    };

    app()->instance($contextClass::class, $contextClass);

    $manager = app(ChunkyManager::class);
    $manager->register($contextClass::class);

    expect($manager->hasContext('class_based'))->toBeTrue();
    expect($manager->getContextRules('class_based'))->toBe(['file_size' => ['max:2048']]);
    expect($manager->getContextSaveCallback('class_based'))->toBeInstanceOf(Closure::class);
});

it('registers a simple context with validation and save callback', function () {
    $manager = app(ChunkyManager::class);

    $manager->simple('photos', 'uploads/photos', [
        'max_size' => 10485760,
        'mimes' => ['image/jpeg', 'image/png'],
    ]);

    expect($manager->hasContext('photos'))->toBeTrue();
    expect($manager->getContextRules('photos'))->toBe([
        'file_size' => ['max:10485760'],
        'mime_type' => ['in:image/jpeg,image/png'],
    ]);
    expect($manager->getContextSaveCallback('photos'))->toBeInstanceOf(Closure::class);
});

it('registers a simple context without options', function () {
    $manager = app(ChunkyManager::class);

    $manager->simple('misc', 'uploads/misc');

    expect($manager->hasContext('misc'))->toBeTrue();
    expect($manager->getContextRules('misc'))->toBe([]);
    expect($manager->getContextSaveCallback('misc'))->toBeInstanceOf(Closure::class);
});

it('initiates an upload and dispatches event', function () {
    Event::fake([UploadInitiated::class]);

    $manager = app(ChunkyManager::class);

    $result = $manager->initiate(
        fileName: 'test-file.pdf',
        fileSize: 5 * 1024 * 1024,
        mimeType: 'application/pdf',
        metadata: ['key' => 'value'],
        context: null,
    );

    expect($result)->toBeInstanceOf(InitiateResult::class);
    expect($result->uploadId)->toBeString();
    expect($result->chunkSize)->toBe(1024 * 1024);
    expect($result->totalChunks)->toBe(5);

    Event::assertDispatched(UploadInitiated::class, function ($event) {
        return $event->fileName === 'test-file.pdf'
            && $event->fileSize === 5 * 1024 * 1024
            && $event->totalChunks === 5;
    });
});

it('returns handler and tracker instances', function () {
    $manager = app(ChunkyManager::class);

    expect($manager->handler())->toBeInstanceOf(ChunkHandler::class);
    expect($manager->tracker())->toBeInstanceOf(UploadTracker::class);
});
