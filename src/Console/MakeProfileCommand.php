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

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Chunky\\Profiles';
    }
}
