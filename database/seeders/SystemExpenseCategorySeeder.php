<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use App\Support\StandardExpenseCategories;
use Illuminate\Database\Seeder;

class SystemExpenseCategorySeeder extends Seeder
{
    public function run(): void
    {
        foreach (StandardExpenseCategories::definitions() as $definition) {
            ExpenseCategory::query()->updateOrCreate(
                [
                    'business_id' => null,
                    'slug' => $definition['slug'],
                ],
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'sort_order' => $definition['sort_order'],
                ],
            );
        }
    }
}
