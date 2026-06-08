<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE businesses MODIFY COLUMN status ENUM('active', 'warning', 'restricted', 'suspended', 'notified') NOT NULL DEFAULT 'active'");
        }
    }

    public function down(): void
    {
        DB::table('businesses')
            ->where('status', 'notified')
            ->update(['status' => 'active']);

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE businesses MODIFY COLUMN status ENUM('active', 'warning', 'restricted', 'suspended') NOT NULL DEFAULT 'active'");
        }
    }
};
