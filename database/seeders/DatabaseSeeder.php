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
        $seeders = [
            PlanSeeder::class,
            SystemRoleSeeder::class,
            SystemExpenseCategorySeeder::class,
            DefaultAccountingTemplateSeeder::class,
            AccountingModuleSeeder::class,
            GuideFaqSeeder::class,
            BusinessCategorySeeder::class,
        ];

        if (app()->environment('staging')) {
            $seeders[] = TestBusinessSeeder::class;
        }

        if (in_array(app()->environment(), ['production', 'local'], true)) {
            $seeders[] = PromoteOwnerBusinessSeeder::class;
            // Ensure Custospark's own ledger accounts exist in the company books
            // (runs after PromoteOwnerBusinessSeeder creates the company business).
            $seeders[] = CompanyAccountingAccountsSeeder::class;
        }

        $this->call($seeders);
    }
}
