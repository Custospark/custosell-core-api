<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quick_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('client_uuid')->unique();
            $table->string('title', 255);
            $table->text('body')->nullable();
            $table->string('color', 20)->nullable();
            $table->string('tag', 60)->nullable();
            $table->boolean('is_shared')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quick_notes');
    }
};
