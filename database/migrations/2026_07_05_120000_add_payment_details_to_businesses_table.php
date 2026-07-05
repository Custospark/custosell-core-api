<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('payment_bank_name', 150)->nullable()->after('receipt_footer');
            $table->string('payment_bank_account_name', 150)->nullable()->after('payment_bank_name');
            $table->string('payment_bank_account_number', 80)->nullable()->after('payment_bank_account_name');
            $table->string('payment_bank_branch', 150)->nullable()->after('payment_bank_account_number');
            $table->string('payment_mobile_money_provider', 100)->nullable()->after('payment_bank_branch');
            $table->string('payment_mobile_money_account_name', 150)->nullable()->after('payment_mobile_money_provider');
            $table->string('payment_mobile_money_number', 50)->nullable()->after('payment_mobile_money_account_name');
            $table->text('payment_instructions')->nullable()->after('payment_mobile_money_number');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn([
                'payment_bank_name',
                'payment_bank_account_name',
                'payment_bank_account_number',
                'payment_bank_branch',
                'payment_mobile_money_provider',
                'payment_mobile_money_account_name',
                'payment_mobile_money_number',
                'payment_instructions',
            ]);
        });
    }
};
