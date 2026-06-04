<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->boolean('is_recurring')->default(false)->after('receipt_path');
            $table->string('recurrence_interval', 20)->nullable()->after('is_recurring');
            $table->date('recurrence_end_date')->nullable()->after('recurrence_interval');
            $table->date('next_due_date')->nullable()->after('recurrence_end_date');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn(['is_recurring', 'recurrence_interval', 'recurrence_end_date', 'next_due_date']);
        });
    }
};
