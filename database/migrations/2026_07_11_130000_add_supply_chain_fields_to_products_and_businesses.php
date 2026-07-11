<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('listed_for_supply')->default(false)->after('is_active');
            $table->decimal('supply_price', 12, 2)->nullable()->after('listed_for_supply');
            $table->integer('supply_min_qty')->default(1)->after('supply_price');
            $table->timestamp('listed_at')->nullable()->after('supply_min_qty');
            $table->index(['business_id', 'listed_for_supply']);
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->boolean('is_open_for_supply')->default(false)->after('status');
            $table->string('supply_headline', 255)->nullable()->after('is_open_for_supply');
            $table->index('is_open_for_supply');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'listed_for_supply']);
            $table->dropColumn(['listed_for_supply', 'supply_price', 'supply_min_qty', 'listed_at']);
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropIndex(['is_open_for_supply']);
            $table->dropColumn(['is_open_for_supply', 'supply_headline']);
        });
    }
};
