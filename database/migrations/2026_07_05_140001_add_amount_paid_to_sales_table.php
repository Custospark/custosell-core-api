<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('amount_paid', 12, 2)->default(0)->after('total_amount');
        });

        DB::table('sales')->where('payment_status', 'paid')->update([
            'amount_paid' => DB::raw('total_amount'),
        ]);
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('amount_paid');
        });
    }
};
