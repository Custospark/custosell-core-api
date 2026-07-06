<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('Running pending migrations…');
        $this->call(MigrateSeeder::class);

        $this->command?->info('Running application seeders…');
        $this->call([
            PlanSeeder::class,
            SystemRoleSeeder::class,
            SystemExpenseCategorySeeder::class,
            AccountingModuleSeeder::class,
        ]);
    }
}
