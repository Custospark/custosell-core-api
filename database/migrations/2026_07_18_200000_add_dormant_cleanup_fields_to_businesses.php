<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->timestamp('last_activity_at')->nullable()->after('status_changed_at');
            $table->timestamp('dormant_notified_at')->nullable()->after('last_activity_at');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['last_activity_at', 'dormant_notified_at']);
        });
    }
};
