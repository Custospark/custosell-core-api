<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_reps', function (Blueprint $table) {
            $table->string('phone', 50)->nullable()->after('referral_code_id');
            $table->string('region', 100)->nullable()->after('phone');
            $table->string('payment_method', 20)->nullable()->after('region');
            $table->string('mobile_money_provider', 50)->nullable()->after('payment_method');
            $table->string('mobile_money_number', 50)->nullable()->after('mobile_money_provider');
            $table->string('mobile_money_name', 255)->nullable()->after('mobile_money_number');
            $table->string('bank_name', 255)->nullable()->after('mobile_money_name');
            $table->string('bank_branch', 255)->nullable()->after('bank_name');
            $table->string('bank_account_name', 255)->nullable()->after('bank_branch');
            $table->string('bank_account_number', 100)->nullable()->after('bank_account_name');
        });
    }

    public function down(): void
    {
        Schema::table('sales_reps', function (Blueprint $table) {
            $table->dropColumn([
                'phone',
                'region',
                'payment_method',
                'mobile_money_provider',
                'mobile_money_number',
                'mobile_money_name',
                'bank_name',
                'bank_branch',
                'bank_account_name',
                'bank_account_number',
            ]);
        });
    }
};
