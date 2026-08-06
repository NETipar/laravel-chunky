<?php

declare(strict_types=1);

use NETipar\Chunky\Tests\Doubles\InMemoryBatchRepository;

batchRepositoryContract(fn (): InMemoryBatchRepository => new InMemoryBatchRepository);
