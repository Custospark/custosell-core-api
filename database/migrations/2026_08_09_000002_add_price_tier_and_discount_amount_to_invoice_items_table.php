<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->string('price_tier', 20)->default('retail')->after('unit_price');
            $table->decimal('discount_amount', 12, 2)->default(0)->after('price_tier');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn(['price_tier', 'discount_amount']);
        });
    }
};