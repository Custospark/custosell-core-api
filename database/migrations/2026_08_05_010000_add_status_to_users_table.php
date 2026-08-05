<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('status')->nullable()->index()->after('is_active');
            $table->timestamp('status_changed_at')->nullable()->after('status');
        });

        DB::table('users')->get(['id', 'is_active', 'updated_at'])->each(function ($user) {
            DB::table('users')->where('id', $user->id)->update([
                'status' => $user->is_active ? 'active' : 'deactivated',
                'status_changed_at' => $user->updated_at,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['status', 'status_changed_at']);
        });
    }
};
