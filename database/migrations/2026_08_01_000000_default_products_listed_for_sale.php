<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('listed_for_supply')->default(true)->change();
            $table->boolean('listed_for_storefront')->default(true)->change();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('listed_for_supply')->default(false)->change();
            $table->boolean('listed_for_storefront')->default(false)->change();
        });
    }
};
