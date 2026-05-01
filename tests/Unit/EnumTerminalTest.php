<?php

declare(strict_types=1);

use NETipar\Chunky\Enums\BatchStatus;
use NETipar\Chunky\Enums\UploadStatus;

it('UploadStatus::isTerminal classifies each case', function () {
    expect(UploadStatus::Pending->isTerminal())->toBeFalse();
    expect(UploadStatus::Assembling->isTerminal())->toBeFalse();
    expect(UploadStatus::Completed->isTerminal())->toBeTrue();
    expect(UploadStatus::Failed->isTerminal())->toBeTrue();
    expect(UploadStatus::Expired->isTerminal())->toBeTrue();
    expect(UploadStatus::Cancelled->isTerminal())->toBeTrue();
});

it('BatchStatus::isTerminal classifies each case', function () {
    expect(BatchStatus::Pending->isTerminal())->toBeFalse();
    expect(BatchStatus::Processing->isTerminal())->toBeFalse();
    expect(BatchStatus::Completed->isTerminal())->toBeTrue();
    expect(BatchStatus::PartiallyCompleted->isTerminal())->toBeTrue();
    expect(BatchStatus::Expired->isTerminal())->toBeTrue();
});
