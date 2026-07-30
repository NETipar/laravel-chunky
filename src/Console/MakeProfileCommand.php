<?php

declare(strict_types=1);

namespace NETipar\Chunky\Console;

use Illuminate\Console\GeneratorCommand;

final class MakeProfileCommand extends GeneratorCommand
{
    protected $name = 'make:chunky-profile';

    protected $description = 'Create a new Chunky upload profile class.';

    protected $type = 'Profile';

    protected function getStub(): string
    {
        return __DIR__.'/stubs/profile.stub';
    }

    // The parameter mirrors the untyped GeneratorCommand signature — typing it
    // would violate LSP on older framework releases.
    protected function getDefaultNamespace($rootNamespace): string // @pest-ignore-type
    {
        return $rootNamespace.'\\Chunky\\Profiles';
    }
}
