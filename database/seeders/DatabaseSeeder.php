<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Canonical entry for `php artisan db:seed`.
 *
 * Calls every seeder under database/seeders/ (except this class):
 * - MigrateSeeder
 * - PlanSeeder
 * - SystemRoleSeeder
 * - SystemExpenseCategorySeeder
 * - DefaultAccountingTemplateSeeder
 * - AccountingModuleSeeder
 */
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
            DefaultAccountingTemplateSeeder::class,
            AccountingModuleSeeder::class,
        ]);
    }
}
