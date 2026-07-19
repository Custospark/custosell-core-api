<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('board_booking_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('break_duration')->default(0)->after('slot_duration');
        });
    }

    public function down(): void
    {
        Schema::table('board_booking_settings', function (Blueprint $table) {
            $table->dropColumn('break_duration');
        });
    }
};
