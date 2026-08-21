<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store the browser timezone captured when a recurring income/expense is
     * created, so the scheduler fires occurrences on the correct local calendar
     * day in the user's timezone (not UTC). Nullable - falls back to UTC.
     */
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('recurrence_timezone', 64)->nullable()->after('recurrence_end_date');
        });

        Schema::table('income_sources', function (Blueprint $table) {
            $table->string('recurrence_timezone', 64)->nullable()->after('recurrence_interval');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn('recurrence_timezone');
        });

        Schema::table('income_sources', function (Blueprint $table) {
            $table->dropColumn('recurrence_timezone');
        });
    }
};