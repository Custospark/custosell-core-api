<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->string('asset_tag')->nullable()->after('notes');
            $table->string('serial_number')->nullable()->after('asset_tag');
            $table->string('category')->nullable()->after('serial_number');
            $table->string('location')->nullable()->after('category');
            $table->string('condition')->nullable()->default('good')->after('location');
            $table->foreignId('assigned_employee_id')
                ->nullable()
                ->after('condition')
                ->constrained('hr_employees')
                ->nullOnDelete();
            $table->timestamp('assigned_at')->nullable()->after('assigned_employee_id');
            $table->timestamp('returned_at')->nullable()->after('assigned_at');

            $table->unique(['business_id', 'asset_tag']);
        });
    }

    public function down(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'asset_tag']);
            $table->dropConstrainedForeignId('assigned_employee_id');
            $table->dropColumn([
                'asset_tag',
                'serial_number',
                'category',
                'location',
                'condition',
                'assigned_at',
                'returned_at',
            ]);
        });
    }
};
