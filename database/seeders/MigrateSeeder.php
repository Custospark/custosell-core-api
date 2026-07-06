<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Runs all pending migrations. Invoked from DatabaseSeeder so a single
 * `php artisan db:seed` bootstraps schema + reference data.
 */
class MigrateSeeder extends Seeder
{
    public function run(): void
    {
        Artisan::call('migrate', ['--force' => true]);

        $output = trim(Artisan::output());
        if ($output !== '') {
            $this->command?->line($output);
        }
    }
}
