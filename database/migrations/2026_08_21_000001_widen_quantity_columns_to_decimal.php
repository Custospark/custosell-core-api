<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Widen all stock / sale quantity columns to decimal so fractional
     * quantities (e.g. 0.5kg sugar) can be sold, stocked, moved, and refunded.
     * Forward-only: existing integer values carry over unchanged.
     */
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('quantity', 10, 3)->default(0)->change();
            $table->decimal('refunded_quantity', 10, 3)->default(0)->change();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->decimal('stock_quantity', 10, 3)->default(0)->change();
        });

        Schema::table('location_product', function (Blueprint $table) {
            $table->decimal('stock_quantity', 10, 3)->default(0)->change();
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->decimal('quantity_change', 10, 3)->default(0)->change();
            $table->decimal('stock_before', 10, 3)->default(0)->change();
            $table->decimal('stock_after', 10, 3)->default(0)->change();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('quantity', 10, 3)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->integer('quantity')->change();
            $table->integer('refunded_quantity')->default(0)->change();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->integer('stock_quantity')->default(0)->change();
        });

        Schema::table('location_product', function (Blueprint $table) {
            $table->integer('stock_quantity')->default(0)->change();
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->integer('quantity_change')->default(0)->change();
            $table->integer('stock_before')->default(0)->change();
            $table->integer('stock_after')->default(0)->change();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedInteger('quantity')->default(0)->change();
        });
    }
};