<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Recurring income mirrors recurring expenses so the engine can forecast
     * future income (next due date + interval) alongside spend.
     */
    public function up(): void
    {
        Schema::table('income_sources', function (Blueprint $table) {
            $table->boolean('is_recurring')->default(false)->after('income_date');
            $table->string('recurrence_interval')->nullable()->after('is_recurring');
            $table->date('next_due_date')->nullable()->after('recurrence_interval');
        });
    }

    public function down(): void
    {
        Schema::table('income_sources', function (Blueprint $table) {
            $table->dropColumn(['is_recurring', 'recurrence_interval', 'next_due_date']);
        });
    }
};