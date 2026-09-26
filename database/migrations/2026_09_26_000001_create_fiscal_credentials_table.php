<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_credentials', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('location_id')->nullable();
            $table->string('country', 10)->default('UG');
            $table->string('tin', 50);
            $table->string('device_no', 100);
            $table->string('branch_id', 50)->nullable();
            $table->string('api_username', 150);
            // Encrypted at rest via the vault service (never plaintext in DB dumps).
            $table->text('api_password');
            $table->string('private_key_path', 255)->nullable();
            $table->string('public_key_path', 255)->nullable();
            $table->string('environment', 20)->default('sandbox');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->foreign('location_id')->references('id')->on('locations')->nullOnDelete();
            $table->unique(['business_id', 'location_id']);
            $table->index(['business_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_credentials');
    }
};
