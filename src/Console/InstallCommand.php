<?php

declare(strict_types=1);

namespace NETipar\Chunky\Console;

use Illuminate\Console\Command;

final class InstallCommand extends Command
{
    protected $signature = 'chunky:install';

    protected $description = 'Publish the Chunky config and migrations.';

    public function handle(): int
    {
        $this->call('vendor:publish', ['--tag' => 'chunky-config']);
        $this->call('vendor:publish', ['--tag' => 'chunky-migrations']);

        $this->newLine();
        $this->info('Chunky installed.');
        $this->line('Next: run `php artisan migrate` to create the chunky tables,');
        $this->line('then register an upload profile in config/chunky.php (or use Chunky::simple()).');

        return self::SUCCESS;
    }
}
