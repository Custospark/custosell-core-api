<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('owner_id');
            $table->string('name', 255);
            $table->string('slug', 255)->unique();
            $table->string('email', 255)->nullable();
            $table->string('phone', 50)->nullable();
            $table->text('address')->nullable();
            $table->string('currency', 10)->default('UGX');
            $table->text('receipt_footer')->nullable();
            $table->string('logo_path', 255)->nullable();
            $table->enum('status', ['active', 'suspended'])->default('active');
            $table->dateTime('trial_ends_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('owner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};
