<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('depreciation_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('fixed_assets');
            $table->foreignId('period_id')->constrained('accounting_periods');
            $table->foreignId('journal_entry_id')->constrained('journal_entries');
            $table->decimal('amount', 16, 2);
            $table->decimal('accumulated_depreciation', 16, 2);
            $table->decimal('book_value_after', 16, 2);
            $table->timestamps();

            $table->unique(['asset_id', 'period_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depreciation_entries');
    }
};
