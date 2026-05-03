<?php

declare(strict_types=1);

use NETipar\Chunky\Jobs\AssembleFileJob;

it('uses the default queue connection when chunky.assembly.connection is null', function () {
    config(['chunky.assembly.connection' => null]);

    $job = new AssembleFileJob('up-x');

    expect($job->connection)->toBeNull();
});

it('routes the assemble job to the configured connection', function () {
    config(['chunky.assembly.connection' => 'sync']);

    $job = new AssembleFileJob('up-y');

    expect($job->connection)->toBe('sync');
});

it('treats an empty-string connection as unset', function () {
    config(['chunky.assembly.connection' => '']);

    $job = new AssembleFileJob('up-z');

    expect($job->connection)->toBeNull();
});

it('routes the assemble job to the configured queue', function () {
    config(['chunky.assembly.queue' => 'uploads']);

    $job = new AssembleFileJob('up-q');

    expect($job->queue)->toBe('uploads');
});

it('honours both connection and queue when both are configured', function () {
    config([
        'chunky.assembly.connection' => 'redis',
        'chunky.assembly.queue' => 'uploads-large',
    ]);

    $job = new AssembleFileJob('up-cq');

    expect($job->connection)->toBe('redis');
    expect($job->queue)->toBe('uploads-large');
});

it('reads tries / backoff / timeout from chunky.assembly config', function () {
    config([
        'chunky.assembly.tries' => 7,
        'chunky.assembly.backoff' => 45,
        'chunky.assembly.timeout' => 1200,
    ]);

    $job = new AssembleFileJob('up-tbt');

    expect($job->tries)->toBe(7);
    expect($job->backoff)->toBe(45);
    expect($job->timeout)->toBe(1200);
});
