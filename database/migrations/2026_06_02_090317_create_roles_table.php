<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->nullable();
            $table->string('name', 100);
            $table->string('slug', 100);
            $table->text('description')->nullable();
            $table->json('permissions');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['business_id', 'slug']);
            $table->index('business_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
