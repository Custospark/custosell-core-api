<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DELETE FROM businesses WHERE deleted_at IS NOT NULL');
        DB::statement('DELETE FROM users WHERE deleted_at IS NOT NULL');
    }

    public function down(): void
    {
        // No rollback - data already removed
    }
};
