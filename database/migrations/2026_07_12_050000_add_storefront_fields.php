<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->boolean('storefront_enabled')->default(false)->after('supply_headline');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('listed_for_storefront')->default(false)->after('listed_at');
            $table->string('image_path')->nullable()->after('listed_for_storefront');
            $table->timestamp('storefront_listed_at')->nullable()->after('image_path');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('source', 32)->default('pos')->after('status');
            $table->string('customer_phone', 40)->nullable()->after('customer_name');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['source', 'customer_phone']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['listed_for_storefront', 'image_path', 'storefront_listed_at']);
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('storefront_enabled');
        });
    }
};
