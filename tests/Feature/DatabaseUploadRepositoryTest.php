<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use NETipar\Chunky\Adapters\Database\DatabaseUploadRepository;

uses(RefreshDatabase::class);

// The shared UploadRepository contract, run against the real database adapter.
uploadRepositoryContract(fn (): DatabaseUploadRepository => new DatabaseUploadRepository);
