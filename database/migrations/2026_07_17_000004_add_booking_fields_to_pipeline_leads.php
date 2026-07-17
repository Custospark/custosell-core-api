<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipeline_leads', function (Blueprint $table) {
            $table->string('booking_status', 20)->nullable()->after('status');
            $table->string('meeting_link', 500)->nullable()->after('booking_status');
            $table->timestamp('approved_at')->nullable()->after('meeting_link');
            $table->timestamp('rejected_at')->nullable()->after('approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('pipeline_leads', function (Blueprint $table) {
            $table->dropColumn(['booking_status', 'meeting_link', 'approved_at', 'rejected_at']);
        });
    }
};
