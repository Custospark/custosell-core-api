<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses');
            $table->foreignId('account_id')->constrained('chart_of_accounts');
            $table->string('name', 200);
            $table->decimal('cost', 16, 2);
            $table->decimal('salvage_value', 16, 2)->default(0);
            $table->integer('useful_life_months');
            $table->date('purchase_date');
            $table->decimal('book_value', 16, 2);
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_assets');
    }
};
