<?php

declare(strict_types=1);

use NETipar\Chunky\Enums\UploadStatus;

it('has expected cases', function () {
    expect(UploadStatus::cases())->toHaveCount(6);
    expect(UploadStatus::Pending->value)->toBe('pending');
    expect(UploadStatus::Assembling->value)->toBe('assembling');
    expect(UploadStatus::Completed->value)->toBe('completed');
    expect(UploadStatus::Failed->value)->toBe('failed');
    expect(UploadStatus::Expired->value)->toBe('expired');
    expect(UploadStatus::Cancelled->value)->toBe('cancelled');
});

it('can be created from string value', function () {
    expect(UploadStatus::from('pending'))->toBe(UploadStatus::Pending);
    expect(UploadStatus::from('completed'))->toBe(UploadStatus::Completed);
});

it('returns null for invalid value with tryFrom', function () {
    expect(UploadStatus::tryFrom('invalid'))->toBeNull();
});
