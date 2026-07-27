<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('payment_method')->nullable()->after('next_payout_at');
            $table->string('mobile_money_provider')->nullable()->after('payment_method');
            $table->string('mobile_money_number')->nullable()->after('mobile_money_provider');
            $table->string('bank_name')->nullable()->after('mobile_money_number');
            $table->string('bank_account_name')->nullable()->after('bank_name');
            $table->string('bank_account_number')->nullable()->after('bank_account_name');
            $table->string('bank_branch')->nullable()->after('bank_account_number');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'payment_method',
                'mobile_money_provider',
                'mobile_money_number',
                'bank_name',
                'bank_account_name',
                'bank_account_number',
                'bank_branch',
            ]);
        });
    }
};
