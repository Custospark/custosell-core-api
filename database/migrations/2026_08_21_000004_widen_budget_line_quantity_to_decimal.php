<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Widen budget plan / shopping-list quantities to decimal so fractional
     * quantities (e.g. 0.5 kg) are respected when planning and converting to
     * an expense. Existing integer values carry over unchanged.
     */
    public function up(): void
    {
        Schema::table('budget_lines', function (Blueprint $table) {
            $table->decimal('quantity', 10, 3)->default(1)->change();
        });
    }

    public function down(): void
    {
        Schema::table('budget_lines', function (Blueprint $table) {
            $table->unsignedInteger('quantity')->default(1)->change();
        });
    }
};