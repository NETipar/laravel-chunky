<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use NETipar\Chunky\Adapters\Database\DatabaseBatchRepository;

uses(RefreshDatabase::class);

batchRepositoryContract(fn (): DatabaseBatchRepository => new DatabaseBatchRepository);
