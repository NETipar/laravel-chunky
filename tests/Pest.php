<?php

declare(strict_types=1);

use NETipar\Chunky\Tests\TestCase;

// Feature tests boot the full Testbench app (service provider, config, DB).
// Unit tests (domain, DTOs, config parsing, in-memory contract) run as plain
// PHP without a Laravel app for speed and isolation.
uses(TestCase::class)->in('Feature');

require_once __DIR__.'/Contracts/UploadRepositoryContract.php';
require_once __DIR__.'/Contracts/BatchRepositoryContract.php';
