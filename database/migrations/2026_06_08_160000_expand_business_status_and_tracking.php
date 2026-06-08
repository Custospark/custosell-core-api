<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->timestamp('status_changed_at')->nullable()->after('status');
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE businesses MODIFY COLUMN status ENUM('active', 'warning', 'restricted', 'suspended') NOT NULL DEFAULT 'active'");
        }

        DB::table('businesses')
            ->whereNull('status_changed_at')
            ->update(['status_changed_at' => DB::raw('COALESCE(updated_at, created_at)')]);
    }

    public function down(): void
    {
        DB::table('businesses')
            ->whereIn('status', ['warning', 'restricted'])
            ->update(['status' => 'active']);

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE businesses MODIFY COLUMN status ENUM('active', 'suspended') NOT NULL DEFAULT 'active'");
        }

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('status_changed_at');
        });
    }
};
