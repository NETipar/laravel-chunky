<?php

declare(strict_types=1);

namespace NETipar\Chunky\Console;

use Illuminate\Console\GeneratorCommand;

class MakeContextCommand extends GeneratorCommand
{
    /** @var string */
    protected $name = 'make:chunky-context';

    /** @var string */
    protected $description = 'Create a new Chunky upload context (validation rules + save callback)';

    /** @var string */
    protected $type = 'Chunky context';

    protected function getStub(): string
    {
        return __DIR__.'/stubs/chunky-context.stub';
    }

    /**
     * @param  string  $rootNamespace
     */
    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Chunky';
    }
}
