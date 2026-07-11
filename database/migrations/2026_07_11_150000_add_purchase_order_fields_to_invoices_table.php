<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('purchase_order_id')->nullable()->after('estimate_id');
            $table->unsignedBigInteger('buyer_business_id')->nullable()->after('purchase_order_id');

            $table->foreign('purchase_order_id')->references('id')->on('purchase_orders')->nullOnDelete();
            $table->foreign('buyer_business_id')->references('id')->on('businesses')->nullOnDelete();
            $table->index('buyer_business_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['purchase_order_id']);
            $table->dropForeign(['buyer_business_id']);
            $table->dropIndex(['buyer_business_id']);
            $table->dropColumn(['purchase_order_id', 'buyer_business_id']);
        });
    }
};
