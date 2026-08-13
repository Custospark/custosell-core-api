<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('converted_at')->nullable()->after('approved_at');
        });

        DB::table('subscriptions')
            ->where('status', 'active')
            ->whereNull('converted_at')
            ->update([
                'converted_at' => DB::raw('COALESCE(approved_at, updated_at)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('converted_at');
        });
    }
};