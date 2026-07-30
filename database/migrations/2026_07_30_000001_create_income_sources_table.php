<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('income_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('source_name');
            $table->text('description')->nullable();
            $table->date('income_date');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'income_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('income_sources');
    }
};
