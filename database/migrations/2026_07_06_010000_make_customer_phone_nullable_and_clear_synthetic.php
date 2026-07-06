<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('phone', 50)->nullable()->change();
        });

        DB::table('customers')
            ->where(function ($query) {
                $query->where('phone', 'like', 'em-%')
                    ->orWhere('phone', 'like', 'walkin-%');
            })
            ->update(['phone' => null]);
    }

    public function down(): void
    {
        DB::table('customers')
            ->whereNull('phone')
            ->update(['phone' => DB::raw("CONCAT('rollback-', id)")]);

        Schema::table('customers', function (Blueprint $table) {
            $table->string('phone', 50)->nullable(false)->change();
        });
    }
};
