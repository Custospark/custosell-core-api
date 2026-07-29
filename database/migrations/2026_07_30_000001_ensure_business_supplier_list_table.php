<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('business_supplier_list')) {
            return;
        }

        Schema::create('business_supplier_list', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('buyer_business_id');
            $table->unsignedBigInteger('seller_business_id');
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['buyer_business_id', 'seller_business_id'], 'biz_supplier_list_unique');
            $table->index(['buyer_business_id', 'created_at']);
            $table->foreign('buyer_business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->foreign('seller_business_id')->references('id')->on('businesses')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // No down. Table lifecycle is owned by 2026_07_11_200000_create_business_supplier_list_table.
    }
};
