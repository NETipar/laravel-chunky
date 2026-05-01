<?php

declare(strict_types=1);

namespace NETipar\Chunky\Console;

use Illuminate\Console\GeneratorCommand;

class MakeAuthorizerCommand extends GeneratorCommand
{
    /** @var string */
    protected $name = 'make:chunky-authorizer';

    /** @var string */
    protected $description = 'Create a new Chunky authorizer (custom upload/batch access policy)';

    /** @var string */
    protected $type = 'Chunky authorizer';

    protected function getStub(): string
    {
        return __DIR__.'/stubs/chunky-authorizer.stub';
    }

    /**
     * @param  string  $rootNamespace
     */
    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Chunky';
    }
}
