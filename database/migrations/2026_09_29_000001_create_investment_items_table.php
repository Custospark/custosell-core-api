<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investment_items', function (Blueprint $table) {
            $table->id();
            // Stable code used by bundles and estimates (e.g. pos-terminal-15).
            $table->string('code')->unique();
            $table->string('category');
            $table->string('name');
            // Model, minimum specs, OS and connectivity needs.
            $table->text('specs')->nullable();
            // Native pricing currency for quotations.
            $table->decimal('price_ugx', 14, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investment_items');
    }
};
