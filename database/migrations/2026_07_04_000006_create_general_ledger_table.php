<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('general_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses');
            $table->foreignId('account_id')->constrained('chart_of_accounts');
            $table->foreignId('period_id')->constrained('accounting_periods');
            $table->decimal('opening_balance', 16, 2)->default(0);
            $table->decimal('total_debits', 16, 2)->default(0);
            $table->decimal('total_credits', 16, 2)->default(0);
            $table->decimal('closing_balance', 16, 2)->default(0);
            $table->timestamps();

            $table->unique(['business_id', 'account_id', 'period_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('general_ledger');
    }
};
