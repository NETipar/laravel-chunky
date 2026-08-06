<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('local'));

// The same UploadRepository contract, run against the filesystem adapter — this
// is what makes driver drift structurally impossible.
uploadRepositoryContract(fn () => makeFilesystemUploadRepository());
