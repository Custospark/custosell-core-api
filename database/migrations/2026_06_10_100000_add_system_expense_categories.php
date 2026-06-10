<?php

use App\Models\ExpenseCategory;
use Database\Seeders\SystemExpenseCategorySeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'name']);
        });

        Schema::table('expense_categories', function (Blueprint $table) {
            $table->unsignedBigInteger('business_id')->nullable()->change();
            $table->string('slug', 100)->nullable()->after('name');
        });

        ExpenseCategory::query()->each(function (ExpenseCategory $category) {
            $category->forceFill([
                'slug' => Str::slug($category->name) ?: 'category-' . $category->id,
            ])->saveQuietly();
        });

        Schema::table('expense_categories', function (Blueprint $table) {
            $table->string('slug', 100)->nullable(false)->change();
            $table->unique(['business_id', 'slug']);
        });

        (new SystemExpenseCategorySeeder())->run();
    }

    public function down(): void
    {
        ExpenseCategory::query()->whereNull('business_id')->delete();

        Schema::table('expense_categories', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'slug']);
            $table->dropColumn('slug');
            $table->unsignedBigInteger('business_id')->nullable(false)->change();
            $table->unique(['business_id', 'name']);
        });
    }
};
