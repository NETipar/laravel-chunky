<?php

declare(strict_types=1);

use NETipar\Chunky\Tests\Doubles\InMemoryUploadRepository;

// Runs the shared UploadRepository contract against the in-memory reference
// adapter. The Database and Filesystem adapters (Phase 2) run this same suite.
uploadRepositoryContract(fn (): InMemoryUploadRepository => new InMemoryUploadRepository);
