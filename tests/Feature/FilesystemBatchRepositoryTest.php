<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('local'));

batchRepositoryContract(fn () => makeFilesystemBatchRepository());
