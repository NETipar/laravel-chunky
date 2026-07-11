<?php

declare(strict_types=1);

use NETipar\Chunky\Domain\Fingerprint;

it('normalizes whitespace and empties to null', function () {
    expect(Fingerprint::fromString('  fp-abc  ')?->value)->toBe('fp-abc');
    expect(Fingerprint::fromString(null))->toBeNull();
    expect(Fingerprint::fromString('   '))->toBeNull();
    expect(Fingerprint::fromString(''))->toBeNull();
});

it('compares by value', function () {
    $a = Fingerprint::fromString('fp-abc');
    $b = Fingerprint::fromString('fp-abc');
    $c = Fingerprint::fromString('fp-xyz');

    expect($a->equals($b))->toBeTrue();
    expect($a->equals($c))->toBeFalse();
    expect($a->equals(null))->toBeFalse();
});
