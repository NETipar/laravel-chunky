<?php

declare(strict_types=1);

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use NETipar\Chunky\Jobs\DoctorProbeJob;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

it('passes all checks on a healthy sync-queue setup', function () {
    $this->artisan('chunky:doctor')
        ->expectsOutputToContain("chunk disk 'local' is writable.")
        ->expectsOutputToContain("final disk 'local' is writable.")
        ->expectsOutputToContain('queue worker picked up the probe job')
        ->expectsOutputToContain('broadcasting disabled')
        ->expectsOutputToContain('locking works')
        ->expectsOutputToContain('database tracker tables exist')
        ->assertExitCode(0);
});

it('skips the queue probe in sync assembly mode', function () {
    config()->set('chunky.assembly.mode', 'sync');

    $this->artisan('chunky:doctor')
        ->expectsOutputToContain('assembly.mode=sync — no queue worker required.')
        ->assertExitCode(0);
});

it('fails when no worker picks up the probe job', function () {
    Queue::fake();

    $this->artisan('chunky:doctor --wait=0')
        ->expectsOutputToContain('no queue worker picked up the probe')
        ->assertExitCode(1);

    Queue::assertPushed(DoctorProbeJob::class);
});

it('fails when the broadcast driver throws', function () {
    config()->set('chunky.broadcasting.enabled', true);
    config()->set('broadcasting.default', 'pusher');
    config()->set('broadcasting.connections.pusher.driver', 'pusher');

    $broadcaster = new class implements Broadcaster
    {
        public function auth($request): mixed
        {
            return null;
        }

        public function validAuthenticationResponse($request, $result): mixed
        {
            return null;
        }

        public function broadcast(array $channels, $event, array $payload = []): void
        {
            throw new RuntimeException('connection refused');
        }
    };

    $this->app->instance(BroadcastFactory::class, new class($broadcaster) implements BroadcastFactory
    {
        public function __construct(private readonly Broadcaster $broadcaster) {}

        public function connection($name = null): Broadcaster
        {
            return $this->broadcaster;
        }
    });

    $this->artisan('chunky:doctor')
        ->expectsOutputToContain("broadcast driver 'pusher' failed to send a test event: connection refused")
        ->assertExitCode(1);
});

it('reports a no-op broadcast driver without failing', function () {
    config()->set('chunky.broadcasting.enabled', true);
    config()->set('broadcasting.default', 'log');
    config()->set('broadcasting.connections.log.driver', 'log');

    $this->artisan('chunky:doctor')
        ->expectsOutputToContain("broadcasting enabled with the 'log' driver")
        ->assertExitCode(0);
});

it('fails when a tracker table is missing', function () {
    Schema::drop('chunky_batches');

    $this->artisan('chunky:doctor')
        ->expectsOutputToContain('database tracker table(s) missing: chunky_batches')
        ->assertExitCode(1);
});

it('probes the filesystem tracker store', function () {
    config()->set('chunky.tracker', 'filesystem');

    $this->artisan('chunky:doctor')
        ->expectsOutputToContain("filesystem tracker can read/write JSON state on the 'local' disk.")
        ->assertExitCode(0);
});
